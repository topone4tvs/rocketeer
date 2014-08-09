<?php
namespace Rocketeer\Services;

use Illuminate\Support\SerializableClosure;
use Mockery;
use Mockery\Mock;
use ReflectionFunction;
use Rocketeer\TestCases\RocketeerTestCase;

class TasksQueueTest extends RocketeerTestCase
{
	public function testCanBuildTaskByName()
	{
		$task = $this->tasksQueue()->buildTaskFromClass('Rocketeer\Tasks\Deploy');

		$this->assertInstanceOf('Rocketeer\Abstracts\AbstractTask', $task);
	}

	public function testCanBuildCustomTaskByName()
	{
		$tasks = $this->tasksQueue()->buildQueue(array('Rocketeer\Tasks\Check'));

		$this->assertInstanceOf('Rocketeer\Tasks\Check', $tasks[0]);
	}

	public function testCanBuildTaskFromString()
	{
		$string = 'echo "I love ducks"';

		$string = $this->tasksQueue()->buildTaskFromClosure($string);
		$this->assertInstanceOf('Rocketeer\Tasks\Closure', $string);

		$closure = $string->getClosure();
		$this->assertInstanceOf('Closure', $closure);

		$closureReflection = new ReflectionFunction($closure);
		$this->assertEquals(array('stringTask' => 'echo "I love ducks"'), $closureReflection->getStaticVariables());

		$this->assertEquals('I love ducks', $string->execute());
	}

	public function testCanBuildTaskFromClosure()
	{
		$originalClosure = function ($task) {
			return $task->getCommand()->info('echo "I love ducks"');
		};

		$closure = $this->tasksQueue()->buildTaskFromClosure($originalClosure);
		$this->assertInstanceOf('Rocketeer\Tasks\Closure', $closure);
		$this->assertEquals($originalClosure, $closure->getClosure());
	}

	public function testCanBuildQueue()
	{
		$queue = array(
			'foobar',
			function () {
				return 'lol';
			},
			'Rocketeer\Tasks\Deploy'
		);

		$queue = $this->tasksQueue()->buildQueue($queue);

		$this->assertInstanceOf('Rocketeer\Tasks\Closure', $queue[0]);
		$this->assertInstanceOf('Rocketeer\Tasks\Closure', $queue[1]);
		$this->assertInstanceOf('Rocketeer\Tasks\Deploy', $queue[2]);
	}

	public function testCanRunQueue()
	{
		$this->swapConfig(array(
			'rocketeer::default' => 'production',
		));

		$this->expectOutputString('JOEY DOESNT SHARE FOOD');
		$this->tasksQueue()->run(array(
			function () {
				print 'JOEY DOESNT SHARE FOOD';
			}
		), $this->getCommand());
	}

	public function testCanRunQueueOnDifferentConnectionsAndStages()
	{
		$this->swapConfig(array(
			'rocketeer::default'       => array('staging', 'production'),
			'rocketeer::stages.stages' => array('first', 'second'),
		));

		$output = array();
		$queue  = array(
			function ($task) use (&$output) {
				$output[] = $task->connections->getConnection().' - '.$task->connections->getStage();
			}
		);

		$queue = $this->tasksQueue()->buildQueue($queue);
		$this->tasksQueue()->run($queue, $this->getCommand());

		$this->assertEquals(array(
			'staging - first',
			'staging - second',
			'production - first',
			'production - second',
		), $output);
	}

	public function testCanRunQueueViaExecute()
	{
		$this->swapConfig(array(
			'rocketeer::default' => 'production',
		));

		$output = $this->tasksQueue()->execute(array(
			'ls -a',
			function () {
				return 'JOEY DOESNT SHARE FOOD';
			}
		));

		$this->assertEquals(array(
			'.'.PHP_EOL.'..'.PHP_EOL.'.gitkeep',
			'JOEY DOESNT SHARE FOOD',
		), $output);
	}

	public function testCanRunOnMultipleConnectionsViaOn()
	{
		$this->swapConfig(array(
			'rocketeer::stages.stages' => array('first', 'second'),
		));

		$output = $this->tasksQueue()->on(array('staging', 'production'), function ($task) {
			return $task->connections->getConnection().' - '.$task->connections->getStage();
		});

		$this->assertEquals(array(
			'staging - first',
			'staging - second',
			'production - first',
			'production - second',
		), $output);
	}

	public function testCanRunTasksInParallel()
	{
		$parallel = Mockery::mock('Parallel')
			->shouldReceive('run')->once()->with(Mockery::type('array'))
			->mock();

		$this->mockCommand(['parallel' => true]);
		$this->tasksQueue()->setParallel($parallel);

		$this->tasksQueue()->execute(['ls', 'ls']);
	}

	public function testCanCancelQueueIfTaskFails()
	{
		$this->expectOutputString('The tasks que was canceled by task "MyCustomHaltingTask"');

		$this->mockCommand([], array(
			'error' => function ($error) {
				echo $error;
			},
		));

		$output = $this->tasksQueue()->execute(array(
			'Rocketeer\Dummies\MyCustomHaltingTask',
			'Rocketeer\Dummies\MyCustomTask',
		));

		$this->assertEquals([false], $output);
	}
}