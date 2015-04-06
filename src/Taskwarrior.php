<?php

namespace DavidBadura\Taskwarrior;

use DavidBadura\Taskwarrior\Exception\CommandException;
use DavidBadura\Taskwarrior\Exception\TaskwarriorException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ProcessBuilder;

/**
 * @author David Badura <d.a.badura@gmail.com>
 */
class Taskwarrior
{
    /**
     * @var array
     */
    private $rcOptions;

    /**
     * @var string
     */
    private $version;

    /**
     * @param string $taskrc
     * @param string $taskData
     * @param array $rcOptions
     */
    public function __construct($taskrc = '~/.taskrc', $taskData = '~/.task', $rcOptions = [])
    {
        $this->rcOptions = array_merge(
            array(
                'rc:' . $taskrc,
                'rc.data.location=' . $taskData,
                'rc.json.array=true',
                'rc.confirmation=no',
            ),
            $rcOptions
        );
    }

    /**
     * @param string $filter
     */
    public function delete($filter)
    {
        $this->command('delete', $filter);
    }

    /**
     * @param string $filter
     */
    public function done($filter)
    {
        $this->command('done', $filter);
    }

    /**
     * @param array $params
     */
    public function add(array $params)
    {
        $this->command('add', null, $this->getOptions($params));
    }

    /**
     * @param array $params
     * @param string|array $filter
     */
    public function modify(array $params, $filter = null)
    {
        $this->command('modify', $filter, $this->getOptions($params));
    }

    /**
     * @param null $filter
     * @return array
     */
    public function projects($filter = null)
    {
        $result = $this->command('_project', $filter);

        return $this->parseResult($result);
    }

    /**
     * @param null $filter
     * @return array
     */
    public function tags($filter = null)
    {
        $result = $this->command('_tags', $filter);

        $tags = $this->parseResult($result);

        return array_values(array_filter($tags, function ($value) {
            return !in_array($value, ['next', 'nocal', 'nocolor', 'nonag']);
        }));
    }

    /**
     * @param string $json
     * @return string
     * @throws CommandException
     * @throws TaskwarriorException
     */
    public function import($json)
    {
        $fs = new Filesystem();

        $file = tempnam(sys_get_temp_dir(), 'task') . '.json';
        $fs->dumpFile($file, $json);

        $output = $this->command('import', $file);

        $fs->remove($file);

        if ($uuid = self::parseUuid($output)) {
            return $uuid;
        }

        throw new TaskwarriorException();
    }

    /**
     * @param string|array $filter
     * @return string
     */
    public function export($filter = null)
    {
        return $this->command('export', $filter);
    }

    /**
     * @param string $command
     * @param string|array $filter
     * @param array $options
     * @return string
     * @throws TaskwarriorException
     */
    public function command($command, $filter = null, array $options = array())
    {
        $builder = $this->createProcessBuilder();

        if (!is_array($filter)) {
            $filter = explode(' ', $filter);
        }

        foreach ($filter as $param) {
            if (empty($param)) {
                continue;
            }

            $builder->add($param);
        }

        $builder->add($command);

        foreach ($options as $param) {
            $builder->add($param);
        }

        /*
         * Local hack to allow utf8 chars
         */
        $oldLocal = setlocale(LC_CTYPE, 0);
        setlocale(LC_CTYPE, 'UTF8', 'en_US.UTF-8');

        $process = $builder->getProcess();

        setlocale(LC_CTYPE, $oldLocal);

        $process->run();

        if (!$process->isSuccessful()) {
            throw new CommandException($process);
        }

        return $process->getOutput();
    }

    /**
     * @return string
     */
    public function version()
    {
        if ($this->version) {
            return $this->version;
        }

        return $this->version = trim($this->command('_version'));
    }

    /**
     * @param $params
     * @return array
     */
    private function getOptions($params)
    {
        $options = [];

        if (array_key_exists('due', $params)) {
            $options[] = 'due:' . $params['due'];
        }

        if (array_key_exists('wait', $params)) {
            $options[] = 'wait:' . $params['wait'];
        }

        if (array_key_exists('until', $params)) {
            $options[] = 'until:' . $params['until'];
        }

        if (array_key_exists('recur', $params)) {
            $options[] = 'recur:' . $params['recur'];
        }

        if (array_key_exists('project', $params)) {
            $options[] = 'project:' . $params['project'];
        }

        if (array_key_exists('priority', $params)) {
            $options[] = 'priority:' . $params['priority'];
        }

        if (array_key_exists('tags', $params)) {
            if (is_array($params['tags'])) {
                $options[] = 'tags:' . implode(',', $params['tags']);
            } else {
                $options[] = 'tags:' . $params['tags'];
            }
        }

        if (array_key_exists('status', $params)) {
            $options[] = 'status:' . $params['status'];
        }

        if (array_key_exists('description', $params)) {
            $options[] = $params['description'];
        }

        return $options;
    }

    /**
     * @return ProcessBuilder
     */
    private function createProcessBuilder()
    {
        $builder = new ProcessBuilder();

        foreach ($this->rcOptions as $option) {
            $builder->add($option);
        }

        $builder->setPrefix('task');
        $builder->setTimeout(360);

        return $builder;
    }

    /**
     * @param string $string
     * @return array
     */
    private function parseResult($string)
    {
        return array_filter(explode("\n", $string), 'strlen');
    }

    /**
     * @param string $string
     * @return string|null
     */
    public static function parseUuid($string)
    {
        if (preg_match('/([0-9a-f]{8}\-[0-9a-f]{4}\-[0-9a-f]{4}\-[0-9a-f]{4}\-[0-9a-f]{12})/', $string, $matches)) {
            return $matches[1];
        }

        return null;
    }
}