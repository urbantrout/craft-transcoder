<?php
/**
 * Transcoder plugin for Craft CMS
 *
 * Transcode videos to various formats, and provide thumbnails of the video
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\transcoder\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\events\DefineAssetThumbUrlEvent;
use craft\fs\Local;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\Json as JsonHelper;
use mikehaertl\shellcommand\Command as ShellCommand;
use nystudio107\transcoder\Transcoder;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\validators\UrlValidator;
use function count;
use function function_exists;
use function in_array;
use function is_bool;

/**
 * @author    nystudio107
 * @package   Transcode
 * @since     1.0.0
 */
class Transcode extends Component
{
    // Constants
    // =========================================================================

    // Suffixes to add to the generated filename params
    protected const SUFFIX_MAP = [
        'videoFrameRate' => 'fps',
        'videoBitRate' => 'bps',
        'audioBitRate' => 'bps',
        'audioChannels' => 'c',
        'height' => 'h',
        'width' => 'w',
        'timeInSecs' => 's',
    ];

    // Params that should be excluded from being part of the generated filename
    protected const EXCLUDE_PARAMS = [
        'videoEncoder',
        'audioEncoder',
        'fileSuffix',
        'sharpen',
        'synchronous',
        'stripMetadata',
        'videoCodecOptions',
    ];

    // Mappings for getFileInfo() summary values
    protected const INFO_SUMMARY = [
        'format' => [
            'filename' => 'filename',
            'duration' => 'duration',
            'size' => 'size',
        ],
        'audio' => [
            'codec_name' => 'audioEncoder',
            'bit_rate' => 'audioBitRate',
            'sample_rate' => 'audioSampleRate',
            'channels' => 'audioChannels',
        ],
        'video' => [
            'codec_name' => 'videoEncoder',
            'bit_rate' => 'videoBitRate',
            'avg_frame_rate' => 'videoFrameRate',
            'height' => 'height',
            'width' => 'width',
        ],
    ];

    // Public Methods
    // =========================================================================

    /**
     * Returns a URL to the transcoded video or "" if it doesn't exist (at
     * which
     * time it will create it).
     *
     * @param string|Asset $filePath string  path to the original video -OR- an
     *                           Asset
     * @param array $videoOptions array   of options for the video
     * @param bool $generate whether the video should be encoded
     *
     * @return string       URL of the transcoded video or ""
     * @throws InvalidConfigException
     */
    public function getVideoUrl(string|Asset $filePath, array $videoOptions, bool $generate = true): string
    {
        $result = '';
        $settings = Transcoder::$plugin->getSettings();
        $subfolder = '';

        // sub folder check
        if (($filePath instanceof Asset) && $settings['createSubfolders']) {
            $subfolder = $filePath->folderPath;
        }

        // file path
        $filePath = $this->getAssetPath($filePath);

        if (!empty($filePath)) {
            $destVideoPath = $settings['transcoderPaths']['video'] . $subfolder ?? $settings['transcoderPaths']['default'];
            $destVideoPath = App::parseEnv($destVideoPath);
            $videoOptions = $this->coalesceOptions('defaultVideoOptions', $videoOptions);

            // Get the video encoder presets to use
            $videoEncoders = $settings['videoEncoders'];
            $thisEncoder = $videoEncoders[$videoOptions['videoEncoder']];

            $videoOptions['fileSuffix'] = $thisEncoder['fileSuffix'];

            // Build the basic command for ffmpeg
            $ffmpegCmd = $settings['ffmpegPath']
                . ' -i ' . escapeshellarg($filePath)
                . ' -vcodec ' . $thisEncoder['videoCodec']
                . ' ' . $thisEncoder['videoCodecOptions']
                . ' -bufsize 1000k'
                . ' -threads ' . $thisEncoder['threads'];

            // Set the framerate if desired
            if (!empty($videoOptions['videoFrameRate'])) {
                $ffmpegCmd .= ' -r ' . $videoOptions['videoFrameRate'];
            }

            // Set the bitrate if desired
            if (!empty($videoOptions['videoBitRate'])) {
                $ffmpegCmd .= ' -b:v ' . $videoOptions['videoBitRate'] . ' -maxrate ' . $videoOptions['videoBitRate'];
            }

            // Adjust the scaling if desired
            $ffmpegCmd = $this->addScalingFfmpegArgs(
                $videoOptions,
                $ffmpegCmd
            );

            // Handle any audio transcoding
            if (empty($videoOptions['audioBitRate'])
                && empty($videoOptions['audioSampleRate'])
                && empty($videoOptions['audioChannels'])
            ) {
                // Just copy the audio if no options are provided
                $ffmpegCmd .= ' -c:a copy';
            } else {
                // Do audio transcoding based on the settings
                $ffmpegCmd .= ' -acodec ' . $thisEncoder['audioCodec'];
                if (!empty($videoOptions['audioBitRate'])) {
                    $ffmpegCmd .= ' -b:a ' . $videoOptions['audioBitRate'];
                }
                if (!empty($videoOptions['audioSampleRate'])) {
                    $ffmpegCmd .= ' -ar ' . $videoOptions['audioSampleRate'];
                }
                if (!empty($videoOptions['audioChannels'])) {
                    $ffmpegCmd .= ' -ac ' . $videoOptions['audioChannels'];
                }
                $ffmpegCmd .= ' ' . $thisEncoder['audioCodecOptions'];
            }

            // Create the directory if it isn't there already
            if (!is_dir($destVideoPath)) {
                try {
                    FileHelper::createDirectory($destVideoPath);
                } catch (Exception $e) {
                    Craft::error($e->getMessage(), __METHOD__);
                }
            }

            $destVideoFile = $this->getFilename($filePath, $videoOptions);

            // File to store the video encoding progress in
            $progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destVideoFile . '.progress';

            // Assemble the destination path and final ffmpeg command
            $destVideoPath .= $destVideoFile;
            $ffmpegCmd .= ' -f '
                . $thisEncoder['fileFormat']
                . ' -y ' . escapeshellarg($destVideoPath)
                . ' 1> ' . $progressFile . ' 2>&1 & echo $!';

            // Make sure there isn't a lockfile for this video already
            $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destVideoFile . '.lock';
            $oldPid = @file_get_contents($lockFile);
            if ($oldPid !== false) {
                // See if the process is running, and empty result means the process is still running
                // ref: https://stackoverflow.com/questions/3043978/how-to-check-if-a-process-id-pid-exists
                exec("kill -0 $oldPid 2>&1", $ProcessState);
                if (count($ProcessState) === 0) {
                    return $result;
                }
                // It's finished transcoding, so delete the lockfile and progress file
                @unlink($lockFile);
                @unlink($progressFile);
            }

            // If the video file already exists and hasn't been modified, return it.  Otherwise, start it transcoding
            if (file_exists($destVideoPath) && (@filemtime($destVideoPath) >= @filemtime($filePath))) {
                $url = $settings['transcoderUrls']['video'] . $subfolder ?? $settings['transcoderUrls']['default'];
                $result = App::parseEnv($url) . $destVideoFile;
                // skip encoding
            } elseif (!$generate) {
                $result = '';
            } else {
                // Kick off the transcoding
                $pid = $this->executeShellCommand($ffmpegCmd);
                Craft::info($ffmpegCmd . "\nffmpeg PID: " . $pid, __METHOD__);

                // Create a lockfile in tmp
                file_put_contents($lockFile, $pid);
            }
        }

        return $result;
    }

    /**
     * Returns a URL to a video thumbnail
     *
     * @param Asset|string $filePath path to the original video or an Asset
     * @param array $thumbnailOptions of options for the thumbnail
     * @param bool $generate whether the thumbnail should be
     *                                 generated if it doesn't exists
     * @param bool $asPath Whether we should return a path or not
     *
     * @return string|false|null URL or path of the video thumbnail
     * @throws InvalidConfigException
     */
    public function getVideoThumbnailUrl(Asset|string $filePath, array $thumbnailOptions, bool $generate = true, bool $asPath = false): string|false|null
    {
        $result = null;
        $settings = Transcoder::$plugin->getSettings();
        $subfolder = '';

        // sub folder check
        if (($filePath instanceof Asset) && $settings['createSubfolders']) {
            $subfolder = $filePath->folderPath;
        }

        $filePath = $this->getAssetPath($filePath);

        if (!empty($filePath)) {
            $destThumbnailPath = $settings['transcoderPaths']['thumbnail'] . $subfolder ?? $settings['transcoderPaths']['default'];
            $destThumbnailPath = App::parseEnv($destThumbnailPath);

            $thumbnailOptions = $this->coalesceOptions('defaultThumbnailOptions', $thumbnailOptions);

            // Build the basic command for ffmpeg
            $ffmpegCmd = $settings['ffmpegPath']
                . ' -i ' . escapeshellarg($filePath)
                . ' -vcodec mjpeg'
                . ' -vframes 1';

            // Adjust the scaling if desired
            $ffmpegCmd = $this->addScalingFfmpegArgs(
                $thumbnailOptions,
                $ffmpegCmd
            );

            // Set the timecode to get the thumbnail from if desired
            if (!empty($thumbnailOptions['timeInSecs'])) {
                $timeCode = gmdate('H:i:s', $thumbnailOptions['timeInSecs']);
                $ffmpegCmd .= ' -ss ' . $timeCode . '.00';
            }

            // Create the directory if it isn't there already
            if (!is_dir($destThumbnailPath)) {
                try {
                    FileHelper::createDirectory($destThumbnailPath);
                } catch (Exception $e) {
                    Craft::error($e->getMessage(), __METHOD__);
                }
            }

            $destThumbnailFile = $this->getFilename($filePath, $thumbnailOptions);

            // Assemble the destination path and final ffmpeg command
            $destThumbnailPath .= $destThumbnailFile;
            $ffmpegCmd .= ' -f image2 -y ' . escapeshellarg($destThumbnailPath) . ' >/dev/null 2>/dev/null &';

            // If the thumbnail file already exists, return it.  Otherwise, generate it and return it
            if (!file_exists($destThumbnailPath)) {
                if ($generate) {
                    /** @noinspection PhpUnusedLocalVariableInspection */
                    $shellOutput = $this->executeShellCommand($ffmpegCmd);
                    Craft::info($ffmpegCmd, __METHOD__);

                    // if ffmpeg fails which we can't check because the process is ran in the background
                    // don't return the future path of the image or else we can't check this in the front end

                } else {
                    Craft::info('Thumbnail does not exist, but not asked to generate it: ' . $filePath, __METHOD__);

                    // The file doesn't exist, and we weren't asked to generate it
                }
                return false;
            }
            // Return either a path or a URL
            if ($asPath) {
                $result = $destThumbnailPath;
            } else {
                $url = $settings['transcoderUrls']['thumbnail'] . $subfolder ?? $settings['transcoderUrls']['default'];
                $result = App::parseEnv($url) . $destThumbnailFile;
            }
        }

        return $result;
    }

    /**
     * Returns a URL to the transcoded audio file or "" if it doesn't exist
     * (at which time it will create it).
     *
     * @param Asset|string $filePath path to the original audio file -OR- an Asset
     * @param array $audioOptions array of options for the audio file
     *
     * @return string       URL of the transcoded audio file or ""
     * @throws InvalidConfigException
     */
    public function getAudioUrl(Asset|string $filePath, array $audioOptions): string
    {
        $result = '';
        $settings = Transcoder::$plugin->getSettings();
        $subfolder = '';

        // sub folder check
        if (($filePath instanceof Asset) && $settings['createSubfolders']) {
            $subfolder = $filePath->folderPath;
        }

        $filePath = $this->getAssetPath($filePath);

        if (!empty($filePath)) {
            $destAudioPath = $settings['transcoderPaths']['audio'] . $subfolder ?? $settings['transcoderPaths']['default'];
            $destAudioPath = App::parseEnv($destAudioPath);

            $audioOptions = $this->coalesceOptions('defaultAudioOptions', $audioOptions);

            // Get the audio encoder presets to use
            $audioEncoders = $settings['audioEncoders'];
            $thisEncoder = $audioEncoders[$audioOptions['audioEncoder']];

            $audioOptions['fileSuffix'] = $thisEncoder['fileSuffix'];

            // Build the basic command for ffmpeg
            $ffmpegCmd = $settings['ffmpegPath']
                . ' -i ' . escapeshellarg($filePath)
                . ' -acodec ' . $thisEncoder['audioCodec']
                . ' ' . $thisEncoder['audioCodecOptions']
                . ' -bufsize 1000k'
                . ' -vn'
                . ' -threads ' . $thisEncoder['threads'];

            // Set the bitrate if desired
            if (!empty($audioOptions['audioBitRate'])) {
                $ffmpegCmd .= ' -b:a ' . $audioOptions['audioBitRate'];
            }
            // Set the sample rate if desired
            if (!empty($audioOptions['audioSampleRate'])) {
                $ffmpegCmd .= ' -ar ' . $audioOptions['audioSampleRate'];
            }
            // Set the audio channels if desired
            if (!empty($audioOptions['audioChannels'])) {
                $ffmpegCmd .= ' -ac ' . $audioOptions['audioChannels'];
            }
            $ffmpegCmd .= ' ' . $thisEncoder['audioCodecOptions'];

            if (!empty($audioOptions['seekInSecs'])) {
                $ffmpegCmd .= ' -ss ' . $audioOptions['seekInSecs'];
            }

            if (!empty($audioOptions['timeInSecs'])) {
                $ffmpegCmd .= ' -t ' . $audioOptions['timeInSecs'];
            }

            // Create the directory if it isn't there already
            if (!is_dir($destAudioPath)) {
                try {
                    FileHelper::createDirectory($destAudioPath);
                } catch (Exception $e) {
                    Craft::error($e->getMessage(), __METHOD__);
                }
            }

            $destAudioFile = $this->getFilename($filePath, $audioOptions);

            // File to store the audio encoding progress in
            $progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destAudioFile . '.progress';

            // Assemble the destination path and final ffmpeg command
            $destAudioPath .= $destAudioFile;
            // Handle the `stripMetadata` setting
            $stripMetadata = false;
            if (!empty($audioOptions['stripMetadata'])) {
                $stripMetadata = $audioOptions['stripMetadata'];
            }
            if ($stripMetadata) {
                $ffmpegCmd .= ' -map_metadata -1 ';
            }
            // Add the file format
            $ffmpegCmd .= ' -f '
                . $thisEncoder['fileFormat']
                . ' -y ' . escapeshellarg($destAudioPath);
            // Handle the `synchronous` setting
            $synchronous = false;
            if (!empty($audioOptions['synchronous'])) {
                $synchronous = $audioOptions['synchronous'];
            }
            if (!$synchronous) {
                $ffmpegCmd .= ' 1> ' . $progressFile . ' 2>&1 & echo $!';
                // Make sure there isn't a lockfile for this audio file already
                $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destAudioFile . '.lock';
                $oldPid = @file_get_contents($lockFile);
                if ($oldPid !== false) {
                    // See if the process is running, and empty result means the process is still running
                    // ref: https://stackoverflow.com/questions/3043978/how-to-check-if-a-process-id-pid-exists
                    exec("kill -0 $oldPid 2>&1", $ProcessState);
                    if (count($ProcessState) === 0) {
                        return $result;
                    }
                    // It's finished transcoding, so delete the lockfile and progress file
                    @unlink($lockFile);
                    @unlink($progressFile);
                }
            }

            // If the audio file already exists and hasn't been modified, return it.  Otherwise, start it transcoding
            if (file_exists($destAudioPath) && (@filemtime($destAudioPath) >= @filemtime($filePath))) {
                $url = $settings['transcoderUrls']['audio'] . $subfolder ?? $settings['transcoderUrls']['default'];
                $result = App::parseEnv($url) . $destAudioFile;
            } else {
                // Kick off the transcoding
                $pid = $this->executeShellCommand($ffmpegCmd);

                if ($synchronous) {
                    Craft::info($ffmpegCmd, __METHOD__);
                    $url = $settings['transcoderUrls']['audio'] . $subfolder ?? $settings['transcoderUrls']['default'];
                    $result = App::parseEnv($url) . $destAudioFile;
                } else {
                    Craft::info($ffmpegCmd . "\nffmpeg PID: " . $pid, __METHOD__);
                    // Create a lockfile in tmp
                    file_put_contents($lockFile, $pid);
                }
            }
        }

        return $result;
    }

    /**
     * Extract information from a video/audio file
     *
     * @param Asset|string $filePath
     * @param bool $summary
     *
     * @return null|array
     * @throws InvalidConfigException
     */
    public function getFileInfo(Asset|string $filePath, bool $summary = false): ?array
    {
        $result = null;
        $settings = Transcoder::$plugin->getSettings();
        $filePath = $this->getAssetPath($filePath);

        if (!empty($filePath)) {
            // Build the basic command for ffprobe
            $ffprobeOptions = $settings['ffprobeOptions'];
            $ffprobeCmd = $settings['ffprobePath']
                . ' ' . $ffprobeOptions
                . ' ' . escapeshellarg($filePath);

            $shellOutput = $this->executeShellCommand($ffprobeCmd);
            Craft::info($ffprobeCmd, __METHOD__);
            $result = JsonHelper::decodeIfJson($shellOutput, true);
            Craft::info(print_r($result, true), __METHOD__);
            // Handle the case it not being JSON
            if (!is_array($result)) {
                $result = [];
            }
            // Trim down the arrays to just a summary
            if ($summary && !empty($result)) {
                $summaryResult = [];
                foreach ($result as $topLevelKey => $topLevelValue) {
                    switch ($topLevelKey) {
                        // Format info
                        case 'format':
                            foreach (self::INFO_SUMMARY['format'] as $settingKey => $settingValue) {
                                if (!empty($topLevelValue[$settingKey])) {
                                    $summaryResult[$settingValue] = $topLevelValue[$settingKey];
                                }
                            }
                            break;
                        // Stream info
                        case 'streams':
                            foreach ($topLevelValue as $stream) {
                                $infoSummaryType = $stream['codec_type'];
                                if (in_array($infoSummaryType, self::INFO_SUMMARY, false)) {
                                    foreach (self::INFO_SUMMARY[$infoSummaryType] as $settingKey => $settingValue) {
                                        if (!empty($stream[$settingKey])) {
                                            $summaryResult[$settingValue] = $stream[$settingKey];
                                        }
                                    }
                                }
                            }
                            break;
                        // Unknown info
                        default:
                            break;
                    }
                }
                // Handle cases where the framerate is returned as XX/YY
                if (!empty($summaryResult['videoFrameRate'])
                    && (str_contains($summaryResult['videoFrameRate'], '/'))
                ) {
                    $parts = explode('/', $summaryResult['videoFrameRate']);
                    $summaryResult['videoFrameRate'] = (float)$parts[0] / (float)$parts[1];
                }
                $result = $summaryResult;
            }
        }

        return $result;
    }

    /**
     * Get the name of a video file from a path and options
     *
     * @param Asset|string $filePath
     * @param array $videoOptions
     *
     * @return string
     * @throws InvalidConfigException
     */
    public function getVideoFilename(Asset|string $filePath, array $videoOptions): string
    {
        $settings = Transcoder::$plugin->getSettings();
        $videoOptions = $this->coalesceOptions('defaultVideoOptions', $videoOptions);

        // Get the video encoder presets to use
        $videoEncoders = $settings['videoEncoders'];
        $thisEncoder = $videoEncoders[$videoOptions['videoEncoder']];

        $videoOptions['fileSuffix'] = $thisEncoder['fileSuffix'];

        return $this->getFilename($filePath, $videoOptions);
    }

    /**
     * Get the name of an audio file from a path and options
     *
     * @param Asset|string $filePath
     * @param array $audioOptions
     *
     * @return string
     * @throws InvalidConfigException
     */
    public function getAudioFilename(Asset|string $filePath, array $audioOptions): string
    {
        $settings = Transcoder::$plugin->getSettings();
        $audioOptions = $this->coalesceOptions('defaultAudioOptions', $audioOptions);

        // Get the video encoder presets to use
        $audioEncoders = $settings['audioEncoders'];
        $thisEncoder = $audioEncoders[$audioOptions['audioEncoder']];

        $audioOptions['fileSuffix'] = $thisEncoder['fileSuffix'];

        return $this->getFilename($filePath, $audioOptions);
    }

    /**
     * Get the name of a gif video file from a path and options
     *
     * @param Asset|string $filePath
     * @param array $gifOptions
     *
     * @return string
     * @throws InvalidConfigException
     */
    public function getGifFilename(Asset|string $filePath, array $gifOptions): string
    {
        $settings = Transcoder::$plugin->getSettings();
        $gifOptions = $this->coalesceOptions('defaultGifOptions', $gifOptions);

        // Get the video encoder presets to use
        $videoEncoders = $settings['videoEncoders'];
        $thisEncoder = $videoEncoders[$gifOptions['videoEncoder']];

        $gifOptions['fileSuffix'] = $thisEncoder['fileSuffix'];

        return $this->getFilename($filePath, $gifOptions);
    }

    /**
     * Handle generated a thumbnail for the Control Panel
     *
     * @param DefineAssetThumbUrlEvent $event
     *
     * @return null|false|string
     * @throws InvalidConfigException
     */
    public function handleGetAssetThumbPath(DefineAssetThumbUrlEvent $event): null|false|string
    {
        $options = [
            'width' => $event->width,
            'height' => $event->height,
        ];
        return $this->getVideoThumbnailUrl($event->asset, $options);
    }

    // Protected Methods
    // =========================================================================

    /**
     * Returns a URL to an encoded GIF file (mp4)
     *
     * @param Asset|string $filePath path to the original video or an Asset
     * @param array $gifOptions of options for the GIF file
     *
     * @return string|false|null URL or path of the GIF file
     * @throws InvalidConfigException
     */

    public function getGifUrl(Asset|string $filePath, array $gifOptions): string|false|null
    {
        $result = '';
        $settings = Transcoder::$plugin->getSettings();
        $subfolder = '';

        // sub folder check
        if (($filePath instanceof Asset) && $settings['createSubfolders']) {
            $subfolder = $filePath->folderPath;
        }

        $filePath = $this->getAssetPath($filePath);

        if (!empty($filePath)) {
            // Dest path
            $destVideoPath = $settings['transcoderPaths']['gif'] . $subfolder ?? $settings['transcoderPaths']['default'];
            $destVideoPath = App::parseEnv($destVideoPath);

            // Options
            $gifOptions = $this->coalesceOptions('defaultGifOptions', $gifOptions);

            // Get the video encoder presets to use
            $videoEncoders = $settings['videoEncoders'];
            $thisEncoder = $videoEncoders[$gifOptions['videoEncoder']];
            $gifOptions['fileSuffix'] = $thisEncoder['fileSuffix'];

            // Build the basic command for ffmpeg
            $ffmpegCmd = $settings['ffmpegPath']
                . ' -f gif'
                . ' -i ' . escapeshellarg($filePath)
                . ' -vcodec ' . $thisEncoder['videoCodec']
                . ' ' . $thisEncoder['videoCodecOptions'];


            // Create the directory if it isn't there already
            if (!is_dir($destVideoPath)) {
                try {
                    FileHelper::createDirectory($destVideoPath);
                } catch (Exception $e) {
                    Craft::error($e->getMessage(), __METHOD__);
                }
            }

            $destVideoFile = $this->getFilename($filePath, $gifOptions);

            // File to store the video encoding progress in
            $progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destVideoFile . '.progress';

            // Assemble the destination path and final ffmpeg command
            $destVideoPath .= $destVideoFile;
            $ffmpegCmd .= ' '
                . ' -y ' . escapeshellarg($destVideoPath)
                . ' 1> ' . $progressFile . ' 2>&1 & echo $!';

            // Make sure there isn't a lockfile for this video already
            $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destVideoFile . '.lock';
            $oldPid = @file_get_contents($lockFile);
            if ($oldPid !== false) {
                // See if the process is running, and empty result means the process is still running
                // ref: https://stackoverflow.com/questions/3043978/how-to-check-if-a-process-id-pid-exists
                exec("kill -0 $oldPid 2>&1", $ProcessState);
                if (count($ProcessState) === 0) {
                    return $result;
                }
                // It's finished transcoding, so delete the lockfile and progress file
                @unlink($lockFile);
                @unlink($progressFile);
            }

            // If the video file already exists and hasn't been modified, return it.  Otherwise, start it transcoding
            if (file_exists($destVideoPath) && (@filemtime($destVideoPath) >= @filemtime($filePath))) {
                $url = $settings['transcoderUrls']['gif'] . $subfolder ?? $settings['transcoderUrls']['default'];
                $result = App::parseEnv($url) . $destVideoFile;
            } else {
                // Kick off the transcoding
                $pid = $this->executeShellCommand($ffmpegCmd);
                Craft::info($ffmpegCmd . "\nffmpeg PID: " . $pid, __METHOD__);

                // Create a lockfile in tmp
                file_put_contents($lockFile, $pid);
            }
        }

        return $result;
    }

    /**
     * Get the name of a file from a path and options
     *
     * @param Asset|string $filePath
     * @param array $options
     *
     * @return string
     * @throws InvalidConfigException
     */
    protected function getFilename(Asset|string $filePath, array $options): string
    {
        $settings = Transcoder::$plugin->getSettings();
        $filePath = $this->getAssetPath($filePath);

        $validator = new UrlValidator();
        $error = '';
        if ($validator->validate($filePath, $error)) {
            $urlParts = parse_url($filePath);
            $pathParts = pathinfo($urlParts['path']);
        } else {
            $pathParts = pathinfo($filePath);
        }
        $fileName = $pathParts['filename'];

        // Add our options to the file name
        foreach ($options as $key => $value) {
            if (isset($value)) {
                $suffix = '';
                if (!empty(self::SUFFIX_MAP[$key])) {
                    $suffix = self::SUFFIX_MAP[$key];
                }
                if (is_bool($value)) {
                    $value = $value ? $key : 'no' . $key;
                }
                if (!in_array($key, self::EXCLUDE_PARAMS, true)) {
                    $fileName .= '_' . $value . $suffix;
                }
            }
        }
        // See if we should use a hash instead
        if ($settings['useHashedNames']) {
            $fileName = $pathParts['filename'] . md5($fileName);
        }
        $fileName .= $options['fileSuffix'];

        return $fileName;
    }

    /**
     * Extract a file system path if $filePath is an Asset object
     *
     * @param Asset|string $filePath
     *
     * @return string
     * @throws InvalidConfigException
     */
    protected function getAssetPath(Asset|string $filePath): string
    {
        // If we're passed an Asset, extract the path from it
        if (($filePath instanceof Asset)) {
            $asset = $filePath;
            $assetVolume = null;
            try {
                $assetVolume = $asset->getVolume();
            } catch (InvalidConfigException $e) {
                Craft::error($e->getMessage(), __METHOD__);
            }

            if ($assetVolume) {
                // If it's local, get a path to the file
                $fs = $assetVolume->getFs();
                if ($fs instanceof Local) {
                    $sourcePath = rtrim($fs->path, DIRECTORY_SEPARATOR);
                    $sourcePath .= '' === $sourcePath ? '' : DIRECTORY_SEPARATOR;
                    $folderPath = '';
                    try {
                        $folderPath = rtrim($asset->getFolder()->path, DIRECTORY_SEPARATOR);
                    } catch (InvalidConfigException $e) {
                        Craft::error($e->getMessage(), __METHOD__);
                    }
                    $folderPath .= '' === $folderPath ? '' : DIRECTORY_SEPARATOR;

                    $filePath = $sourcePath . $folderPath . $asset->filename;
                } else {
                    // Otherwise, get a URL
                    $filePath = $asset->getUrl() ?? '';
                }
            }
        }

        $filePath = (string)App::parseEnv($filePath);

        // Make sure that $filePath is either an existing file, or a valid URL
        if (!file_exists($filePath)) {
            $validator = new UrlValidator();
            $error = '';
            if (!$validator->validate($filePath, $error)) {
                Craft::error($error, __METHOD__);
                $filePath = '';
            }
        }

        return $filePath;
    }

    /**
     * Set the width & height if desired
     *
     * @param array $options
     * @param string $ffmpegCmd
     *
     * @return string
     */
    protected function addScalingFfmpegArgs(array $options, string $ffmpegCmd): string
    {
        if (!empty($options['width']) && !empty($options['height'])) {
            // Handle "none", "crop", and "letterbox" aspectRatios
            $aspectRatio = '';
            if (!empty($options['aspectRatio'])) {
                switch ($options['aspectRatio']) {
                    // Scale to the appropriate aspect ratio, padding
                    case 'letterbox':
                        $letterboxColor = '';
                        if (!empty($options['letterboxColor'])) {
                            $letterboxColor = ':color=' . $options['letterboxColor'];
                        }
                        $aspectRatio = ':force_original_aspect_ratio=decrease'
                            . ',pad=' . $options['width'] . ':' . $options['height'] . ':(ow-iw)/2:(oh-ih)/2'
                            . $letterboxColor;
                        break;
                    // Scale to the appropriate aspect ratio, cropping
                    case 'crop':
                        $aspectRatio = ':force_original_aspect_ratio=increase'
                            . ',crop=' . $options['width'] . ':' . $options['height'];
                        break;
                    // No aspect ratio scaling at all
                    default:
                        $aspectRatio = ':force_original_aspect_ratio=disable';
                        $options['aspectRatio'] = 'none';
                        break;
                }
            }
            $sharpen = '';
            if (!empty($options['sharpen']) && ($options['sharpen'] !== false)) {
                $sharpen = ',unsharp=5:5:1.0:5:5:0.0';
            }
            $ffmpegCmd .= ' -vf "scale='
                . $options['width'] . ':' . $options['height']
                . $aspectRatio
                . $sharpen
                . '"';
        }

        return $ffmpegCmd;
    }

    // Protected Methods
    // =========================================================================

    /**
     * Combine the options arrays
     *
     * @param string $defaultName
     * @param array $options
     *
     * @return array
     */
    protected function coalesceOptions(string $defaultName, array $options): array
    {
        // Default options
        $settings = Transcoder::$plugin->getSettings();
        $defaultOptions = $settings[$defaultName];

        // Coalesce the passed in $options with the $defaultOptions
        return array_merge($defaultOptions, $options);
    }

    /**
     * Execute a shell command
     *
     * @param string $command
     *
     * @return string
     */
    protected function executeShellCommand(string $command): string
    {
        // Create the shell command
        $shellCommand = new ShellCommand();
        $shellCommand->setCommand($command);

        // If we don't have proc_open, maybe we've got exec
        if (!function_exists('proc_open') && function_exists('exec')) {
            $shellCommand->useExec = true;
        }

        // Return the result of the command's output or error
        if ($shellCommand->execute()) {
            $result = $shellCommand->getOutput();
        } else {
            $result = $shellCommand->getError();
        }

        return $result;
    }
}
