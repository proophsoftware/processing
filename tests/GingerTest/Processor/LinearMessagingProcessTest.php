<?php
/*
 * This file is part of the Ginger Workflow Framework.
 * (c) Alexander Miertsch <contact@prooph.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 * 
 * Date: 05.10.14 - 14:38
 */

namespace GingerTest\Processor;

use Ginger\Message\MessageNameUtils;
use Ginger\Message\ProophPlugin\HandleWorkflowMessageInvokeStrategy;
use Ginger\Message\WorkflowMessage;
use Ginger\Processor\LinearMessagingProcess;
use Ginger\Processor\RegistryWorkflowEngine;
use Ginger\Processor\Task\CollectData;
use Ginger\Processor\Task\ProcessData;
use GingerTest\Mock\TestWorkflowMessageHandler;
use GingerTest\Mock\UserDictionary;
use GingerTest\TestCase;
use Prooph\ServiceBus\CommandBus;
use Prooph\ServiceBus\EventBus;
use Prooph\ServiceBus\InvokeStrategy\CallbackStrategy;
use Prooph\ServiceBus\Router\CommandRouter;
use Prooph\ServiceBus\Router\EventRouter;

/**
 * Class LinearMessagingProcessTest
 *
 * @package GingerTest\Processor
 * @author Alexander Miertsch <kontakt@codeliner.ws>
 */
class LinearMessagingProcessTest extends TestCase
{
    /**
     * @var RegistryWorkflowEngine
     */
    private $workflowEngine;

    /**
     * @var TestWorkflowMessageHandler
     */
    private $workflowMessageHandler;

    /**
     * @var CommandRouter
     */
    private $commandRouter;

    protected function setUp()
    {
        $this->workflowMessageHandler = new TestWorkflowMessageHandler();

        $commandBus = new CommandBus();

        $this->commandRouter = new CommandRouter();

        $this->commandRouter->route(MessageNameUtils::getCollectDataCommandName('GingerTest\Mock\UserDictionary'))
            ->to($this->workflowMessageHandler);

        $commandBus->utilize($this->commandRouter);

        $commandBus->utilize(new HandleWorkflowMessageInvokeStrategy());

        $this->workflowEngine = new RegistryWorkflowEngine();

        $this->workflowEngine->registerCommandBus($commandBus, ['test-case', 'test-target']);
    }

    /**
     * @test
     */
    public function it_performs_collect_data_as_first_task_if_no_initial_wfm_is_given()
    {
        $task = CollectData::from('test-case', UserDictionary::prototype());

        $process = LinearMessagingProcess::setUp([$task]);

        $process->perform($this->workflowEngine);

        $collectDataMessage = $this->workflowMessageHandler->lastWorkflowMessage();

        $this->assertInstanceOf('Ginger\Message\WorkflowMessage', $collectDataMessage);

        $this->assertEquals('GingerTest\Mock\UserDictionary', $collectDataMessage->getPayload()->getTypeClass());

        $this->assertFalse($process->isChildProcess());

        $this->assertFalse($process->isFinished());

        $this->workflowMessageHandler->reset();

        //It should not perform the task twice
        $process->perform($this->workflowEngine);

        $this->assertNull($this->workflowMessageHandler->lastWorkflowMessage());
    }

    /**
     * @test
     */
    public function it_finishes_the_task_when_it_receives_an_answer_message()
    {
        $task = CollectData::from('test-case', UserDictionary::prototype());

        $process = LinearMessagingProcess::setUp([$task]);

        $wfm = WorkflowMessage::collectDataOf(UserDictionary::prototype());

        $answer = $wfm->answerWith(UserDictionary::fromNativeValue([
            'id' => 1,
            'name' => 'John Doe',
            'address' => [
                'street' => 'Main Street',
                'streetNumber' => 10,
                'zip' => '12345',
                'city' => 'Test City'
            ]
        ]));

        $this->workflowMessageHandler->setNextAnswer($answer);

        $eventBus = new EventBus();

        $eventBus->utilize(new EventRouter([
            $answer->getMessageName() => [
                function (WorkflowMessage $answer) use ($process) {
                    $process->receiveMessage($answer, $this->workflowEngine);
                }
            ]
        ]))->utilize(new CallbackStrategy());

        $this->workflowMessageHandler->useEventBus($eventBus);

        $process->perform($this->workflowEngine);

        $this->assertTrue($process->isFinished());

        $this->assertTrue($process->isSuccessfulDone());
    }

    /**
     * @test
     */
    public function it_marks_task_list_entry_as_failed_if_command_dispatch_fails()
    {
        $task = CollectData::from('test-case', UserDictionary::prototype());

        $process = LinearMessagingProcess::setUp([$task]);

        //We deactivate the router so message cannot be dispatched
        $this->workflowEngine->getCommandBusFor('test-case')->deactivate($this->commandRouter);

        $process->perform($this->workflowEngine);

        $this->assertTrue($process->isFinished());

        $this->assertFalse($process->isSuccessfulDone());
    }

    /**
     * @test
     */
    public function it_performs_next_task_after_receiving_answer_for_previous_task()
    {
        $task1 = CollectData::from('test-case', UserDictionary::prototype());

        $task2 = ProcessData::address('test-target', ['GingerTest\Mock\UserDictionary']);

        $process = LinearMessagingProcess::setUp([$task1, $task2]);

        $wfm = WorkflowMessage::collectDataOf(UserDictionary::prototype());

        $answer1 = $wfm->answerWith(UserDictionary::fromNativeValue([
            'id' => 1,
            'name' => 'John Doe',
            'address' => [
                'street' => 'Main Street',
                'streetNumber' => 10,
                'zip' => '12345',
                'city' => 'Test City'
            ]
        ]));

        $this->workflowMessageHandler->setNextAnswer($answer1);

        //Fake follow up task execution
        $processDataMessage = $answer1->prepareDataProcessing();

        $this->commandRouter->route($processDataMessage->getMessageName())->to($this->workflowMessageHandler);

        $answer2 = $processDataMessage->answerWithDataProcessingCompleted();

        $eventBus = new EventBus();

        $eventRouter = new EventRouter([
            $answer1->getMessageName() => [
                function (WorkflowMessage $answer) use ($process, $answer2) {
                    $this->workflowMessageHandler->setNextAnswer($answer2);
                    $process->receiveMessage($answer, $this->workflowEngine);
                }
            ],
            $answer2->getMessageName() => [
                function (WorkflowMessage $answer) use ($process) {
                    $process->receiveMessage($answer, $this->workflowEngine);
                }
            ]
        ]);

        $eventBus->utilize($eventRouter)->utilize(new CallbackStrategy());

        $this->workflowMessageHandler->useEventBus($eventBus);

        $process->perform($this->workflowEngine);

        $this->assertTrue($process->isFinished());

        $this->assertTrue($process->isSuccessfulDone());
    }

    /**
     * @test
     */
    public function it_changes_type_class_if_target_does_not_allow_the_source_type()
    {
        $task = ProcessData::address('test-target', ['GingerTest\Mock\TargetUserDictionary']);

        $process = LinearMessagingProcess::setUp([$task]);

        $wfm = WorkflowMessage::collectDataOf(UserDictionary::prototype());

        $answer = $wfm->answerWith(UserDictionary::fromNativeValue([
            'id' => 1,
            'name' => 'John Doe',
            'address' => [
                'street' => 'Main Street',
                'streetNumber' => 10,
                'zip' => '12345',
                'city' => 'Test City'
            ]
        ]));

        $processDataMessage = $answer->prepareDataProcessing();

        $this->commandRouter->route($processDataMessage->getMessageName())->to($this->workflowMessageHandler);

        $process->perform($this->workflowEngine, $answer);

        $receivedMessage = $this->workflowMessageHandler->lastWorkflowMessage();

        $this->assertEquals('GingerTest\Mock\TargetUserDictionary', $receivedMessage->getPayload()->getTypeClass());
    }
}
 