<?php

namespace LuckyTeam\Yii2\Jira\Console\Controller;

use LuckyTeam\Jira\Entity\ReadonlyIssue as Issue;
use LuckyTeam\Jira\Provider\IssueLinksProvider;
use LuckyTeam\Jira\Util\IssueLinkHelper;
use LuckyTeam\Yii2\Jira\Repository\RepositoryDispatcher;
use yii\console\Controller;
use yii\di\Instance;
use yii\helpers\ArrayHelper;

class IssueLinkTreeController extends Controller
{
    /**
     * @var int
     */
    public $depth = 2;

    /**
     * @var string
     */
    public $links;

    /**
     * @var string
     */
    public $projects;

    public $statuses;

    /**
     * @var RepositoryDispatcher
     */
    private $dispatcher;

    /**
     * @var string[]
     */
    private $components = [
        'jira' => 'jiraService',
    ];

    /**
     * @var IssueLinksProvider
     */
    private $provider;

    private $pattern = '{{prefix}} {{link_name}} {{key}} {{summary}} [{{status_name}}]' . PHP_EOL;
    private $patternVariables;

    private $issueQuery = [
        'fields' => ['id', 'summary', 'project', 'issuelinks', 'status'],
        'expand' => [],
    ];

    private $filters = [];
    private $filterValues = [];

    private $outputtedIssues = [];
    private $walkedIssues = [];
    private $nestedOutputtedIssues = [];
    private $allOutputtedIssues = [];

    private function needOutputIssueTree(Issue $issue, $linkName = null, $depth = 0)
    {
        $canOutput = false;
        $issueIndex = $this->getIndexByIssue($issue);

        if ($this->depth > $depth) {
            if (!array_key_exists($issueIndex, $this->outputtedIssues)) {
                if (!$this->isOutputtedIssueTree($issue, $linkName, $depth)) {
                    $issueLinks = $issue->getLinks();
                    foreach ($issueLinks as $issueLink) {
                        $linkedIssueKey = IssueLinkHelper::getLinkedIssueKey($issueLink);
                        if (isset($linkedIssueKey) && $issueIndex !== $linkedIssueKey) {
                            if ($linkedIssue = $this->provider->getIssueByKey($linkedIssueKey)) {
                                $canOutput = $this->needOutputIssueTree($linkedIssue, $linkName, $depth + 1);
                                if ($canOutput) {
                                    break;
                                }
                            }
                        }
                    }
                } else {
                    $canOutput = true;
                }

                $this->outputtedIssues[$issueIndex] = $canOutput;
            } else {
                $canOutput = $this->outputtedIssues[$issueIndex];
            }
        }

        return $canOutput;
    }

    private function isOutputtedIssueTree(Issue $issue, $linkName = null, $depth = 0)
    {
        $isOutputted = !isset($linkName) || !isset($this->filterValues['type_links'])
            || in_array($linkName, $this->filterValues['type_links']);

        if ($isOutputted && isset($this->filterValues['projects'])) {
            $isOutputted = in_array(ArrayHelper::getValue($issue->getProject(), 'key'), $this->filterValues['projects']);
        }

        if ($isOutputted && isset($this->filterValues['statuses'])) {
            $isOutputted = in_array(ArrayHelper::getValue($issue->getStatus(), 'name'), $this->filterValues['statuses']);
        }

        return $isOutputted;
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        $this->dispatcher = Instance::ensure($this->components['jira']);
    }

    /**
     * @inheritdoc
     */
    public function options($actionID)
    {
        return ArrayHelper::merge(parent::options($actionID), [
            'depth', 'links', 'projects', 'statuses'
        ]);
    }

    /**
     * @param string $rootIssueKey An root of issue key
     *
     * План работ
     * - Добавить фильтр по проекту, которе нужно включить
     * - Дать возможность указать шаблон для того, чтобы вывести на экран список задач
     * - Подвести итог и вывести его в подвале
     * - Дать возможность указать в подвале список задач
     */
    public function actionIndex($rootIssueKey)
    {
        if (isset($this->links)) {
            $this->setOutputtedTypeLinks(explode(",", $this->links));
        }

        if (isset($this->projects)) {
            $this->setOutputtedProjects(explode(",", $this->projects));
        }

        if (isset($this->statuses)) {
            $this->setOutputtedStatuses(explode(",", $this->statuses));
        }

        $rootIssue = $this->dispatcher->getIssue(array_merge(
            $this->issueQuery, [
                'key' => $rootIssueKey,
            ]
        ));

        $this->provider = new IssueLinksProvider($rootIssue);
        $this->provider
            ->setDispatcher($this->dispatcher)
            ->setIssueQuery(
                array_merge(
                    $this->issueQuery, [
                        'startAt' => 0,
                        'maxResults' => 1000,
                    ]
                )
            )
            ->setMaxDepth($this->depth)
            ->build();

        $this->walkIssueTree($rootIssue);

        ob_start();
        $this->outputIssueTree($rootIssue);
        $outputCache = ob_get_clean();

        $this->outputFilterValuesList();

        echo '======' . PHP_EOL;

        echo $outputCache;
    }

    public function walkIssueTree(Issue $issue, $linkName = null, $depth = 0)
    {
        $issueIndex = $this->getIndexByIssue($issue);
        $this->addIssueToFilterValuesList($issue);

        if ($this->depth > $depth) {
            // Если ранее этот элемент не обходили
            if (!array_key_exists($issueIndex, $this->walkedIssues)) {
                $this->outputtedIssues[$issueIndex] = $this->isOutputtedIssueTree($issue, $linkName, $depth);

                // Если есть имя связи, то пометить что данная связь уже рассматривалась
                if (isset($linkName)) {
                    $this->walkedIssues[$issueIndex] = [
                        $linkName => true,
                    ];
                    // Или пометить что данная задача уже рассматривалась без учета связи
                } else {
                    $this->walkedIssues[$issueIndex] = [];
                }

                $issueLinks = $issue->getLinks();
                foreach ($issueLinks as $issueLink) {
                    $linkName = IssueLinkHelper::getLinkName($issueLink);
                    if (isset($linkName)) {
                        $this->addValueToFilterValuesList('Link types', $linkName, $linkName);
                    }

                    $linkedIssueKey = IssueLinkHelper::getLinkedIssueKey($issueLink);
                    if (isset($linkedIssueKey) && $issueIndex !== $linkedIssueKey) {
                        $linkedIssue = $this->provider->getIssueByKey($linkedIssueKey);
                        if ($linkedIssue) {
                            $this->walkIssueTree($linkedIssue, $linkName, $depth + 1);
                        }
                    }
                }

                // Если ранее этот элемент рассматривали с другой связью
            } elseif (isset($linkName) && !array_key_exists($linkName, $this->walkedIssues[$issueIndex])) {
                $this->walkedIssues[$issueIndex] = [
                    $linkName => true,
                ];

                // Если ранее принято решение не показывать элемент, пересмотреть его с новой связью
                if (!$this->outputtedIssues[$issueIndex]) {
                    $this->outputtedIssues[$issueIndex] = $this->isOutputtedIssueTree($issue, $linkName, $depth);
                }
            }
        }

        // В ином случае этот элемент ранее уже рассматривался
    }

    /**
     * @param Issue $issue
     * @param string $linkName
     * @param int $depth
     */
    public function outputIssueTree(Issue $issue, $linkName = null, $depth = 0)
    {
        $issueIndex = $this->getIndexByIssue($issue);

        if (($this->depth > $depth)
            && $this->needOutputIssueTree($issue, $linkName, $depth)
        ) {
            if (!array_key_exists($issueIndex, $this->allOutputtedIssues)) {
                $this->outputIssue($issue, $linkName, $depth);
                $this->allOutputtedIssues[$issueIndex] = true;
                $this->nestedOutputtedIssues[$issueIndex] = true;
                $cachedOutputtedIssues = $this->nestedOutputtedIssues;

                $issueLinks = $issue->getLinks();
                foreach ($issueLinks as $issueLink) {
                    $linkName = IssueLinkHelper::getLinkName($issueLink);
                    $linkedIssueKey = IssueLinkHelper::getLinkedIssueKey($issueLink);

                    if (isset($linkedIssueKey) && $issueIndex !== $linkedIssueKey) {
                        $linkedIssue = $this->provider->getIssueByKey($linkedIssueKey);
                        if (isset($linkedIssue)) {
                            $this->outputIssueTree($linkedIssue, $linkName, $depth + 1);
                        }
                    }
                }

                $this->nestedOutputtedIssues = $cachedOutputtedIssues;

            } else {
                if (!array_key_exists($issueIndex, $this->nestedOutputtedIssues)) {
                    $this->nestedOutputtedIssues[$issueIndex] = true;
                    $this->outputIssue($issue, $linkName, $depth);
                }
            }
        }
    }

    /**
     * @param array $outputtedTypeLinks
     */
    public function setOutputtedTypeLinks(array $outputtedTypeLinks)
    {
        $this->filterValues['type_links'] = $outputtedTypeLinks;
    }

    private function setOutputtedProjects(array $outputtedProjects)
    {
        $this->filterValues['projects'] = $outputtedProjects;
    }

    private function setOutputtedStatuses(array $outputtedStatuses)
    {
        $this->filterValues['statuses'] = $outputtedStatuses;
    }

    /**
     * @param string $name
     */
    public function setJiraComponentName(string $name)
    {
        $this->components['jira'] = $name;
    }

    /**
     * @return array
     */
    public function outputFilterValuesList()
    {
        foreach ($this->filters as $name => $value) {
            echo $name . ': ' . implode(', ', array_keys($this->filters[$name])) . PHP_EOL;
        }
    }

    /**
     * @param Issue $issue
     * @param $linkName
     * @param $depth
     */
    public function outputIssue(Issue $issue, $linkName = null, $depth = 0)
    {
        $variables = [];
        foreach ($this->getPatternVariables() as $variable) {
            if ('prefix' == $variable) {
                $variables[$variable] = str_repeat('-', $depth + 1);
            } elseif ('link_name' == $variable) {
                $variables[$variable] = $linkName;
            } elseif ('key' == $variable) {
                $variables[$variable] = $issue->getKey();
            } elseif ('summary' == $variable) {
                $variables[$variable] = $issue->getSummary();
            } elseif ('status_name' == $variable) {
                $status = $issue->getStatus();
                if (isset($status['name'])) {
                    $variables[$variable] = $status['name'];
                } else {
                    $variables[$variable] = '';
                }
            }
        }

        $line = preg_replace("/\s{2,}/", ' ', preg_replace_callback("/\{\{(\S+?)\}\}/", function ($matches) use ($variables) {
            if (isset($variables[$matches[1]])) {
                return $variables[$matches[1]];
            }
            return '';
        }, $this->pattern));

        echo $line;
    }

    public function getPatternVariables()
    {
        if (!isset($this->patternVariables)) {
            if (preg_match_all("/\{\{(\S+?)\}\}/", $this->pattern, $matches) <= 0) {
                // Ошибка
            }
            $this->patternVariables = end($matches);
        }

        return $this->patternVariables;
    }

    public function addIssueToFilterValuesList(Issue $issue)
    {
        // Collects all projects keys
        $project = $issue->getProject();
        if (is_array($project) && isset($project['key'], $project['name'])) {
            $this->addValueToFilterValuesList('Projects', $project['key'], $project['name']);
        }

        // Collects all statuses keys
        $status = $issue->getStatus();
        if (is_array($status) && isset($status['name'], $status['description'])) {
            $this->addValueToFilterValuesList('Statuses', $status['name'], $status['description']);
        }
    }

    public function addValueToFilterValuesList($name, $key, $desc)
    {
        if (!array_key_exists($name, $this->filters)) {
            $this->filters[$name] = [];
        }
        $this->filters[$name][$key] = $desc;
    }

    /**
     * @param Issue $issue
     * @return string
     */
    public function getIndexByIssue(Issue $issue)
    {
        return $issue->getKey();
    }
}
