<?php

namespace AsyncPHP\Doorman\Manager;

use AsyncPHP\Doorman\Expires;
use AsyncPHP\Doorman\Manager;
use AsyncPHP\Doorman\Process;
use AsyncPHP\Doorman\Profile;
use AsyncPHP\Doorman\Profile\InMemoryProfile;
use AsyncPHP\Doorman\Rule;
use AsyncPHP\Doorman\Rules;
use AsyncPHP\Doorman\Rules\InMemoryRules;
use AsyncPHP\Doorman\Shell;
use AsyncPHP\Doorman\Shell\BashShell;
use AsyncPHP\Doorman\Task;
use SplObjectStorage;

class ProcessManager implements Manager
{
    /**
     * @var Task[]
     */
    protected $waiting = array();

    /**
     * @var Task[]
     */
    protected $running = array();

    /**
     * @var null|SplObjectStorage
     */
    protected $timings = null;

    /**
     * @var null|string
     */
    protected $logPath;

    /**
     * @var null|Rules
     */
    protected $rules;

    /**
     * @var null|Shell
     */
    protected $shell;

    /**
     * @inheritdoc
     *
     * @param Task $task
     *
     * @return $this
     */
    public function addTask(Task $task)
    {
        $this->waiting[] = $task;

        return $this;
    }

    /**
     * @inheritdoc
     *
     * @return bool
     */
    public function tick()
    {
        if (!$this->timings instanceof SplObjectStorage) {
            $this->timings = new SplObjectStorage();
        }

        $waiting = array();
        $running = array();

        foreach ($this->waiting as $task) {
            if (!$this->canRunTask($task)) {
                $waiting[] = $task;
                continue;
            }

            if ($task->stopsSiblings()) {
                $this->stopSiblingTasks($task);
            }

            $binary = $this->getBinary();
            $worker = $this->getWorker();
            $stdout = $this->getStdOut();
            $stderr = $this->getStdErr();

            if ($task instanceof Expires) {
                $this->timings[$task] = time();
            }

            $pid = $this->getShell()->exec("{$binary} {$worker} %s {$stdout} {$stderr} & echo $!", array(
                $this->getTaskString($task),
            ));

            if ($task instanceof Process) {
                $task->setId($pid);
            }

            $this->running[] = $task;
        }

        foreach ($this->running as $task) {
            if (!$this->canRemoveTask($task)) {
                $running[] = $task;
            }
        }

        $this->waiting = $waiting;
        $this->running = $running;

        return count($this->waiting) > 0 || count($this->running) > 0;
    }

    /**
     * @todo description
     *
     * @param Task $task
     *
     * @return $this
     */
    protected function stopSiblingTasks(Task $task)
    {
        $handler = $task->getHandler();

        foreach ($this->running as $task) {
            if ($task->getHandler() === $handler && $task instanceof Process) {
                $this->getShell()->exec("kill -9 %s", array(
                    $task->getId(),
                ));
            }
        }

        return $this;
    }

    /**
     * @todo description
     *
     * @param Task $task
     *
     * @return bool
     */
    protected function canRunTask(Task $task)
    {
        if ($task->ignoresRules()) {
            return true;
        }

        $processes = array_filter($this->running, function (Task $task) {
            return $task instanceof Process;
        });

        if (count($processes) < 1) {
            return true;
        }

        $profile = $this->getProfileForProcesses($task, $processes);

        return $this->getRules()->canRunTask($task, $profile);
    }

    /**
     * @todo description
     *
     * @param Task  $task
     * @param array $processes
     *
     * @return Profile
     */
    protected function getProfileForProcesses(Task $task, array $processes)
    {
        $stats = $this->getStatsForProcesses($processes);

        $siblingProcesses = array_filter($processes, function (Task $next) use ($task) {
            return $next->getHandler() === $task->getHandler();
        });

        $siblingStats = $this->getStatsForProcesses($siblingProcesses);

        $profile = $this->newProfile();

        $profile->setProcesses($processes);
        $profile->setProcessorLoad(array_sum(array_column($stats, 1)));
        $profile->setMemoryLoad(array_sum(array_column($stats, 2)));

        $profile->setSiblingProcesses($siblingProcesses);
        $profile->setSiblingProcessorLoad(array_sum(array_column($siblingStats, 1)));
        $profile->setSiblingMemoryLoad(array_sum(array_column($siblingStats, 2)));

        return $profile;
    }

    /**
     * @todo description
     *
     * @param Process[] $processes
     *
     * @return array
     */
    protected function getStatsForProcesses(array $processes)
    {
        $stats = array();

        foreach ($processes as $process) {
            $result = $this->getShell()->exec("ps -o pid,%%cpu,%%mem,state,start -p %s", array(
                $process->getId(),
            ));

            if (trim($result) === "") {
                continue;
            }

            $stats[] = preg_split("/\s+/", $result);
        }

        return $stats;
    }

    /**
     * @todo description
     *
     * @return Shell
     */
    public function getShell()
    {
        if ($this->shell === null) {
            $this->shell = $this->newShell();
        }

        return $this->shell;
    }

    /**
     * @todo description
     *
     * @param Shell $shell
     *
     * @return $this
     */
    public function setShell(Shell $shell)
    {
        $this->shell = $shell;

        return $this;
    }

    /**
     * @todo description
     *
     * @return Shell
     */
    protected function newShell()
    {
        return new BashShell();
    }

    /**
     * @todo description
     *
     * @return Profile
     */
    protected function newProfile()
    {
        return new InMemoryProfile();
    }

    /**
     * @todo description
     *
     * @return Rules
     */
    public function getRules()
    {
        if ($this->rules === null) {
            $this->rules = $this->newRules();
        }

        return $this->rules;
    }

    /**
     * @todo description
     *
     * @param Rules $rules
     *
     * @return $this
     */
    public function setRules(Rules $rules)
    {
        $this->rules = $rules;

        return $this;
    }

    /**
     * @todo description
     *
     * @return Rules
     */
    protected function newRules()
    {
        return new InMemoryRules();
    }

    /**
     * @todo description
     *
     * @return string
     */
    protected function getBinary()
    {
        return PHP_BINDIR . "/php";
    }

    /**
     * @todo description
     *
     * @return string
     */
    protected function getWorker()
    {
        return realpath(__DIR__ . "/../../bin/worker.php");
    }

    /**
     * @todo description
     *
     * @return string
     */
    protected function getStdOut()
    {
        if ($this->getLogPath()) {
            return ">> " . $this->getLogPath() . "/stdout.log";
        }

        return "> /dev/null";
    }

    /**
     * @todo description
     *
     * @return bool
     */
    public function getLogPath()
    {
        return $this->logPath;
    }

    /**
     * @todo description
     *
     * @param string $logPath
     *
     * @return $this
     */
    public function setLogPath($logPath)
    {
        $this->logPath = $logPath;

        return $this;
    }

    /**
     * @todo description
     *
     * @return string
     */
    protected function getStdErr()
    {
        if ($this->getLogPath()) {
            return "2>> " . $this->getLogPath() . "/stderr.log";
        }

        return "2> /dev/null";
    }

    /**
     * @todo description
     *
     * @param Task $task
     *
     * @return string
     */
    protected function getTaskString(Task $task)
    {
        return base64_encode(serialize($task));
    }

    /**
     * @todo description
     *
     * @param Task $task
     *
     * @return bool
     */
    protected function canRemoveTask(Task $task)
    {
        if (!$task instanceof Process) {
            return true;
        }

        if ($task instanceof Expires) {
            $expiresIn = $task->getExpiresIn();
            $startedAt = $this->timings[$task];

            if ($expiresIn > 0 && (time() - $startedAt) >= $expiresIn) {
                if ($task->shouldExpire($startedAt)) {
                    if ($task instanceof Process) {
                        $this->getShell()->exec("kill -9 %s", array(
                            $task->getId(),
                        ));
                    }

                    return true;
                }
            }
        }

        $processes = array_filter($this->running, function (Task $task) {
            return $task instanceof Process;
        });

        if (count($processes) < 1) {
            return true;
        }

        $found = false;
        $stats = $this->getStatsForProcesses($processes);

        foreach ($stats as $stat) {
            if ($stat[0] === $task->getId()) {
                $found = true;
            }
        }

        return !$found;
    }

    /**
     * @todo description
     *
     * @param Rule $rule
     *
     * @return $this
     */
    public function addRule(Rule $rule)
    {
        $this->getRules()->addRule($rule);

        return $this;
    }

    /**
     * @todo description
     *
     * @param Rule $rule
     *
     * @return $this
     */
    public function removeRule(Rule $rule)
    {
        $this->getRules()->removeRule($rule);

        return $this;
    }
}