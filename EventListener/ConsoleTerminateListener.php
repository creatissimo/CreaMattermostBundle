<?php
/**
 * Log symfony exceptionst to Mattermost
 *
 * User: pascal
 * Date: 16.10.16
 * Time: 21:33
 */

namespace Creatissimo\MattermostBundle\EventListener;

use Creatissimo\MattermostBundle\Entity\Message;
use Creatissimo\MattermostBundle\Services\AttachmentHelper;
use Creatissimo\MattermostBundle\Services\MattermostService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;

class ConsoleTerminateListener
{
    /** @var MattermostService */
    private $mmService;

    /** @var AttachmentHelper */
    private $attachmentHelper;

    /** @var Command $command */
    private $command;

    /** @var InputInterface $input */
    private $input;

    /** @var integer */
    private $exitCode;

    /**
     * ConsoleTerminateListener constructor.
     *
     * @param MattermostService $mmService
     * @param AttachmentHelper  $attachmentHelper
     */
    public function __construct(MattermostService $mmService, AttachmentHelper $attachmentHelper)
    {
        $this->mmService        = $mmService;
        $this->attachmentHelper = $attachmentHelper;
    }

    /**
     * Handle the exception
     *
     * @param ConsoleTerminateEvent $event
     */
    public function onConsoleTerminate(ConsoleTerminateEvent $event)
    {
        if ($this->mmService->isEnabled('terminate')) {
            $config = $this->mmService->getEnvironmentConfiguration();
            if (!empty($config)) {
                $this->exitCode = $event->getExitCode();

                if ($config['enable'] && $this->shouldProcessExitCode($this->exitCode)) {
                    $this->command = $event->getCommand();
                    $this->input   = $event->getInput();
                    $this->postToMattermost();
                }
            }
        }
    }

    /**
     * Post exception details to Mattermost
     */
    protected function postToMattermost()
    {
        $message = new Message('Command has been terminated; ExitCode: ' . $this->exitCode);

        $attachment = $this->attachmentHelper->convertCommandToAttachment($this->command, $this->input);
        $message->addAttachment($attachment);

        $this->mmService->setMessage($message)->send();
    }


    /**
     * Check to see if this exitcode is in an exclude list
     *
     * @param integer $exitCode
     *
     * @return bool
     */
    public function shouldProcessExitCode($exitCode)
    {
        $shouldProcess = true;
        $config        = $this->mmService->getEnvironmentConfiguration();
        if (!empty($config) && array_key_exists('terminate', $config)) {
            $exceptionConf = $config['terminate'];
            if (array_key_exists('exclude_exitcode', $exceptionConf)) {
                $excludeList = $exceptionConf['exclude_exitcode'];
                foreach ($excludeList as $exclude) {
                    if ($exclude == $exitCode) {
                        $shouldProcess = false;
                        break;
                    }
                }
            }
        }

        return $shouldProcess;
    }
}