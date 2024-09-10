<?php
/**
 * Log symfony exceptionst to Mattermost
 *
 * User: pascal
 * Date: 16.10.16
 * Time: 21:33
 */

namespace Creatissimo\MattermostBundle\Services;

use Creatissimo\MattermostBundle\Constant\ExceptionConstant;
use Creatissimo\MattermostBundle\Entity\Attachment;
use Creatissimo\MattermostBundle\Entity\AttachmentField;
use Creatissimo\MattermostBundle\Entity\Message;

class ExceptionHelper
{
    private MattermostService $mmService;

    public function __construct(MattermostService $mmService)
    {
        $this->mmService = $mmService;
    }

    /**
     * Format the JSON message to post to Mattermost
     *
     * @throws \Throwable
     */
    public function convertExceptionToMessage(\Throwable $throwable, $exceptionChannel = null, $source = null, bool $trace = false): Message
    {
        $code          = $throwable->getCode();
        $message       = $throwable->getMessage();
        $file          = $throwable->getFile();
        $line          = $throwable->getLine();
        $fullClassName = get_class($throwable);
        $className     = preg_replace('/^.*\\\\([^\\\\]+)$/', '$1', $fullClassName);
        $now           = new \DateTime();

        $text = '#### ';
        $text .= $className . ' thrown in ' . $this->mmService->getAppName();
        if ($source) {
            $text .= " @ \n" . $source;
        }

        $mmMessage = new Message($text);
        if ($exceptionChannel !== null) {
            $mmMessage->setChannel($exceptionChannel);
        }

        $attachment = new Attachment($fullClassName);
        $attachment->setColor(ExceptionConstant::EXCEPTION_COLOR)
                   ->setFallback($message);

        $attachment->addField(new AttachmentField('Message', $message));
        $attachment->addField(new AttachmentField('File', $file));
        $attachment->addField(new AttachmentField('Line', (string)$line, true));
        $attachment->addField(new AttachmentField('Code', (string)$code, true));
        $attachment->addField(new AttachmentField('System', $this->mmService->getAppName(), true));
        $attachment->addField(new AttachmentField('Environment', $this->mmService->getEnvironment(), true));
        $attachment->addField(new AttachmentField('Timestamp', $now->format(DATE_ATOM), true));

        if ($trace || $this->shouldAddTrace()) {
            $attachment->addField(new AttachmentField('Trace', $this->getThrowableTraceAsString($throwable)));
        }

        $mmMessage->addAttachment($attachment);

        return $mmMessage;
    }

    /**
     * @param \Throwable  $exception
     * @param null        $source
     * @param null|string $exceptionChannel
     * @param bool        $trace
     *
     * @return bool
     * @throws \Throwable
     */
    public function sendException(\Throwable $exception, $exceptionChannel = null, $source = null, bool $trace = false): bool
    {
        $message = $this->convertExceptionToMessage($exception, $exceptionChannel, $source, $trace);

        return $this->mmService->setMessage($message)->send();
    }

    /**
     * Check if trace should be added
     *
     * @return bool
     */
    public function shouldAddTrace(): bool
    {
        $config = $this->mmService->getEnvironmentConfiguration();
        if (!empty($config) && array_key_exists('exception', $config)) {
            $exceptionConf = $config['exception'];
            if (array_key_exists('trace', $exceptionConf) && $exceptionConf['trace']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check to see if this exception is in an exclude list
     */
    public function shouldProcessException(\Throwable $throwable): bool
    {
        $shouldProcess = true;
        $config        = $this->mmService->getEnvironmentConfiguration();
        if (!empty($config) && array_key_exists('exception', $config)) {
            $exceptionConf = $config['exception'];
            if (array_key_exists('exclude_class', $exceptionConf)) {
                $className   = get_class($throwable);
                $excludeList = $exceptionConf['exclude_class'];
                foreach ($excludeList as $exclude) {
                    if ($exclude == $className) {
                        $shouldProcess = false;
                        break;
                    }
                }
            }
        }

        return $shouldProcess;
    }

    private function getThrowableTraceAsString(\Throwable $throwable): string
    {
        $rtn   = '';
        $count = 0;
        foreach ($throwable->getTrace() as $frame) {
            $args = '';
            if (isset($frame['args'])) {
                $argList = [];
                foreach ($frame['args'] as $arg) {
                    if (is_string($arg)) {
                        $argList[] = "'" . $arg . "'";
                    } elseif (is_array($arg)) {
                        $argList[] = 'Array';
                    } elseif (null === $arg) {
                        $argList[] = 'NULL';
                    } elseif (is_bool($arg)) {
                        $argList[] = $arg ? 'true' : 'false';
                    } elseif (is_object($arg)) {
                        $argList[] = get_class($arg);
                    } elseif (is_resource($arg)) {
                        $argList[] = get_resource_type($arg);
                    } else {
                        $argList[] = $arg;
                    }
                }
                $args = implode(', ', $argList);
            }
            $rtn .= sprintf("#%s %s(%s): %s(%s)\n",
                $count,
                $frame['file'] ?? 'unknown file',
                $frame['line'] ?? 'unknown line',
                isset($frame['class']) ? $frame['class'] . $frame['type'] . $frame['function'] : $frame['function'],
                $args);
            $count++;
        }

        return $rtn;
    }
}