<?php

namespace LuckyTeam\Yii2\Jira\Web\Widget;

use LuckyTeam\Jira\Entity\ReadonlyIssue as Issue;
use LuckyTeam\Jira\Provider\IssueLinksProvider;
use LuckyTeam\Jira\Util\IssueLinkHelper;
use yii\base\Widget;
use yii\helpers\Html;

/**
 * Class IssuesLinksTreeView
 * @package LuckyTeam\Yii2\Jira\Widget
 */
class IssuesLinksTreeView extends Widget
{
    /**
     * @var Issue of Jira
     */
    public $root;

    /**
     * @var IssueLinksProvider of Jira issues
     */
    public $provider;

    /**
     * @var integer Max depth of tree
     */
    public $maxDepth = 1;

    /**
     * @var array An array of rendered Issues
     */
    private $renderedIssues = [];

    /**
     * @var array An array of nested rendered Issues
     */
    private $nestedRenderedIssues = [];

    /**
     * @var callable Needed of rendering
     */
    private $issueTreeRenderNeeded;

    /**
     * @var callable Issue rendering callback
     */
    private $issueRender;

    /**
     * @inheritdoc
     */
    public function run()
    {
        return $this->renderIssueTree($this->root);
    }

    /**
     * Checks needed of rendering
     *
     * @return bool Needed of rendering
     */
    private function needRenderIssueTree()
    {
        $needRender = true;

        if (is_callable($this->issueTreeRenderNeeded)) {
            $needRender = call_user_func_array($this->issueTreeRenderNeeded, func_get_args());
        }

        return $needRender;
    }

    /**
     * Renders issue trees
     *
     * @param Issue $issue Jira issue
     * @param string $linkName Name of link
     * @param int $depth Current depth of tree
     *
     * @return string Result of rendering
     */
    public function renderIssueTree(Issue $issue, $linkName = null, $depth = 0)
    {
        $items = [];

        if (($this->maxDepth > $depth)
            && $this->needRenderIssueTree($issue, $linkName, $depth)
        ) {
            $issueIndex = $issue->getKey();
            if (!array_key_exists($issueIndex, $this->renderedIssues)) {
                $items[] = $this->renderIssue($issue, $linkName, $depth);
                $this->renderedIssues[$issueIndex] = true;
                $this->nestedRenderedIssues[$issueIndex] = true;
                $cachedRenderedIssues = $this->nestedRenderedIssues;

                $issueLinks = $issue->getLinks();
                foreach ($issueLinks as $issueLink) {
                    $linkName = IssueLinkHelper::getLinkName($issueLink);
                    $linkedIssueKey = IssueLinkHelper::getLinkedIssueKey($issueLink);

                    if (isset($linkedIssueKey) && $issue->getKey() !== $linkedIssueKey) {
                        $linkedIssue = $this->provider->getIssueByKey($linkedIssueKey);
                        if (isset($linkedIssue)) {
                            $items[] = $this->renderIssueTree($linkedIssue, $linkName, $depth + 1);
                        }
                    }
                }
                $this->nestedRenderedIssues = $cachedRenderedIssues;
            } else {
                if (!array_key_exists($issue->getKey(), $this->nestedRenderedIssues)) {
                    $items[] = $this->renderIssue($issue, $linkName, $depth + 1);
                    $this->nestedRenderedIssues[$issueIndex] = true;
                }
            }

            return $this->renderTreeContent(implode('', $items));
        }

        return '';
    }

    /**
     * Renders content of tree
     *
     * @param string $content Content of tree
     *
     * @return string Result of rendering
     */
    public function renderTreeContent($content)
    {
        return Html::tag('ul', $content);
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
        if (is_callable($this->issueRender)) {
            return call_user_func_array($this->issueRender, func_get_args());
        } else {
            return Html::tag('li', $issue->getKey() . ' ' . $issue->getSummary());
        }
    }

    /**
     * Sets needed of rendering callable
     *
     * @param callable $issueTreeRenderNeeded Needed of rendering callable
     */
    public function setIssueTreeRenderNeeded(callable $issueTreeRenderNeeded)
    {
        $this->issueTreeRenderNeeded = $issueTreeRenderNeeded;
    }

    /**
     * Sets issue rendering callback
     *
     * @param callable $issueRender Issue rendering callback
     */
    public function setIssueRender(callable $issueRender)
    {
        $this->issueRender = $issueRender;
    }
}
