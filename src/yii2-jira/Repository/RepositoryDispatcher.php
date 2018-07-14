<?php

namespace LuckyTeam\Yii2\Jira\Repository;

use LuckyTeam\Jira\Repository\RepositoryDispatcher as Dispatcher;
use yii\base\Configurable;
use yii\base\InvalidConfigException;

class RepositoryDispatcher extends Dispatcher implements Configurable
{
    /**
     * RepositoryDispatcher constructor
     * @param array $config Name-value pairs that will be used to initialize the object properties
     * @throws InvalidConfigException If property was not configured
     * @see \LuckyTeam\Jira\Repository\RepositoryDispatcher
     * - $endpoint An endpoint of service
     * - $username An name of user
     * - $password An password of user
     */
    public function __construct($config = [])
    {
        if (!isset($config['endpoint'])) {
            throw new InvalidConfigException('The property \'endpoint\' was not configured (see ' . Dispatcher::class . '::$endpoint)');
        } elseif (!isset($config['password'])) {
            throw new InvalidConfigException('The property \'username\' was not configured (see ' . Dispatcher::class . '::$username)');
        } elseif (!isset($config['password'])) {
            throw new InvalidConfigException('The property \'password\' was not configured (see ' . Dispatcher::class . '::$password)');
        }

        parent::__construct($config['endpoint'], $config['username'], $config['password']);
    }
}
