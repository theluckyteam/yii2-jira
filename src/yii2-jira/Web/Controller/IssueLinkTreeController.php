<?php

namespace LuckyTeam\Yii2\Jira\Web\Controller;

use LuckyTeam\Jira\Entity\ReadonlyIssue as Issue;
use LuckyTeam\Jira\Provider\IssueLinksProvider;
use LuckyTeam\Jira\Util\IssueLinkHelper;
use LuckyTeam\Yii2\Jira\Repository\RepositoryDispatcher as Dispatcher;
use yii\di\Instance;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\web\Controller;

/**
 * Class IssueLinkTreeController
 * @package LuckyTeam\Yii2\Jira\Web\Controller
 */
class IssueLinkTreeController extends Controller
{
    /**
     * @var Dispatcher of Jira repositories
     */
    private $dispatcher;

    /**
     * @var IssueLinksProvider
     */
    private $provider;

    /**
     * @var array Query of issue
     */
    private $issueQuery = [
        'fields' => ['id', 'summary', 'project', 'issuelinks', 'status'],
        'expand' => [],
    ];

    /**
     * @var integer Max depth of tree
     */
    private $maxDepth;

    /**
     * @var array An array of rendered issues
     */
    private $renderedIssues = [];

    /**
     * @var array An array of walked issues
     */
    private $walkedIssues = [];

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        $this->dispatcher = Instance::ensure('jiraService');
    }


    /**
     * Returns tree of
     */
    public function actionIndex($key, $depth = 1)
    {
        $this->maxDepth = $depth;

        $rootIssue = $this->dispatcher->getIssue(array_merge(
            $this->issueQuery, [
                'key' => $key,
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
            ->setMaxDepth($depth)
            ->build();

        $this->walkIssueTree($rootIssue);

        return $this->renderPartial('index', [
            'widgetOptions' => [
                'root' => $rootIssue,
                'maxDepth' => $depth,
                'provider' => $this->provider,
                /** @see IssueLinkTreeController::needRenderIssueTree */
                'issueTreeRenderNeeded' => [$this, 'needRenderIssueTree'],
                /** @see IssueLinkTreeController::renderIssue */
                'issueRender' => [$this, 'renderIssue'],
            ],
        ]);
    }

    /**
     * Checks needed of rendering
     *
     * @param Issue $issue Jira issue
     * @param string $linkName Name of link
     * @param int $depth Current depth of tree
     *
     * @return bool Needed of rendering
     */
    public function needRenderIssueTree(Issue $issue, $linkName = null, $depth = 0)
    {
        $needRender = false;

        $issueIndex = $issue->getKey();
        if ($this->maxDepth > $depth) {
            if (!array_key_exists($issueIndex, $this->renderedIssues)) {
                if (!$this->isRenderedIssueTree($issue, $linkName, $depth)) {
                    $issueLinks = $issue->getLinks();
                    foreach ($issueLinks as $issueLink) {
                        $linkedIssueKey = IssueLinkHelper::getLinkedIssueKey($issueLink);
                        if (isset($linkedIssueKey) && $issueIndex !== $linkedIssueKey) {
                            if ($linkedIssue = $this->provider->getIssueByKey($linkedIssueKey)) {
                                $needRender = $this->needRenderIssueTree($linkedIssue, $linkName, $depth + 1);
                                if ($needRender) {
                                    break;
                                }
                            }
                        }
                    }
                } else {
                    $needRender = true;
                }

                $this->renderedIssues[$issueIndex] = $needRender;
            } else {
                $needRender = $this->renderedIssues[$issueIndex];
            }
        }

        return $needRender;
    }

    /**
     * Checks needed of rendering
     *
     * @param Issue $issue Jira issue
     * @param string $linkName Name of link
     * @param int $depth Current depth of tree
     *
     * @return bool Needed of rendering
     */
    public function isRenderedIssueTree(Issue $issue, $linkName = null, $depth = 0)
    {
        $needRender = false;

        /*if (mb_strpos($issue->getKey(), 'NOAH') !== false) {
            $needRender = true;
        }*/

        /*if (in_array($linkName, ['is parent task of', 'is subtask of'])) {
            $needRender = true;
        }*/

        $needRender = true;

        return $needRender;
    }

    /**
     * Prepares issue tree
     *
     * @param Issue $issue Jira issue
     * @param string $linkName Name of link
     * @param int $depth Current depth of tree
     */
    public function walkIssueTree(Issue $issue, $linkName = null, $depth = 0)
    {
        $issueIndex = $issue->getKey();

        if ($this->maxDepth > $depth) {
            // Если ранее этот элемент не обходили
            if (!array_key_exists($issueIndex, $this->walkedIssues)) {
                // Если есть имя связи, то пометить что данная связь уже рассматривалась
                if (isset($linkName)) {
                    $this->walkedIssues[$issueIndex] = [
                        $linkName => true,
                    ];
                // Или пометить что данная задача уже рассматривалась без учета связи
                } else {
                    $this->walkedIssues[$issueIndex] = [];
                }

                // Если ранее принято решение не показывать элемент, пересмотреть его с новой связью
                if (!isset($this->renderedIssues[$issueIndex])) {
                    $this->renderedIssues[$issueIndex] = $this->isRenderedIssueTree($issue, $linkName, $depth);
                }

                $issueLinks = $issue->getLinks();
                foreach ($issueLinks as $issueLink) {
                    $linkName = IssueLinkHelper::getLinkName($issueLink);
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
                if (!$this->renderedIssues[$issueIndex]) {
                    $this->renderedIssues[$issueIndex] = $this->isRenderedIssueTree($issue, $linkName, $depth);
                }
            }
        }

        // В ином случае этот элемент ранее уже рассматривался
    }

    /**
     * Renders issue
     *
     * @param Issue $issue Jira issue
     * @param string $linkName Name of link
     * @param int $depth Current depth of tree
     *
     * @return string Result of rendering
     */
    public function renderIssue(Issue $issue, $linkName = null, $depth = 0)
    {
        if (isset($linkName)) {
            return Html::tag('li', $linkName . ' ' . $issue->getKey() . ' ' . $issue->getSummary() . ' ' . '[' . ArrayHelper::getValue($issue->getStatus(), 'name') . ']');
        } else {
            return Html::tag('li', $issue->getKey() . ' ' . $issue->getSummary() . ' ' . '[' . ArrayHelper::getValue($issue->getStatus(), 'name') . ']');
        }
    }
}
