<?php

namespace Yandex\Allure\Adapter;

use Codeception\Configuration;
use Codeception\Event\StepEvent;
use Codeception\Event\SuiteEvent;
use Codeception\Event\TestEvent;
use Codeception\Event\FailEvent;
use Codeception\Events;
use Codeception\Platform\Extension;
use Codeception\Exception\ConfigurationException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Filesystem\Filesystem;
use Yandex\Allure\Adapter\Annotation;
use Yandex\Allure\Adapter\Event\StepFailedEvent;
use Yandex\Allure\Adapter\Event\StepFinishedEvent;
use Yandex\Allure\Adapter\Event\StepStartedEvent;
use Yandex\Allure\Adapter\Event\TestCaseBrokenEvent;
use Yandex\Allure\Adapter\Event\TestCaseCanceledEvent;
use Yandex\Allure\Adapter\Event\TestCaseFailedEvent;
use Yandex\Allure\Adapter\Event\TestCaseFinishedEvent;
use Yandex\Allure\Adapter\Event\TestCasePendingEvent;
use Yandex\Allure\Adapter\Event\TestCaseStartedEvent;
use Yandex\Allure\Adapter\Event\TestSuiteFinishedEvent;
use Yandex\Allure\Adapter\Event\TestSuiteStartedEvent;
use Yandex\Allure\Adapter\Model;

const OUTPUT_DIRECTORY_PARAMETER = 'outputDirectory';
const DELETE_PREVIOUS_RESULTS_PARAMETER = 'deletePreviousResults';
const DEFAULT_RESULTS_DIRECTORY = 'allure-results';
const DEFAULT_REPORT_DIRECTORY = 'allure-report';

class AllureAdapter extends Extension
{
    //NOTE: here we implicitly assume that PHP runs in single-threaded mode
    private $uuid;

    /**
     * @var Allure
     */
    private $lifecycle;

    static $events = [
        Events::SUITE_BEFORE => 'suiteBefore',
        Events::SUITE_AFTER => 'suiteAfter',
        Events::TEST_BEFORE => 'testBefore',
        Events::TEST_START => 'testStart',
        Events::TEST_FAIL => 'testFail',
        Events::TEST_ERROR => 'testError',
        Events::TEST_INCOMPLETE => 'testIncomplete',
        Events::TEST_SKIPPED => 'testSkipped',
        Events::TEST_END => 'testEnd',
        Events::STEP_BEFORE => 'stepBefore',
        Events::STEP_AFTER => 'stepAfter'
    ];

    /**
     * Annotations that should be ignored by the annotaions parser (especially PHPUnit annotations).
     * 
     * @var array
     */
    private $ignoredAnnotations = [
        'after', 'afterClass', 'backupGlobals', 'backupStaticAttributes', 'before', 'beforeClass',
        'codeCoverageIgnore', 'codeCoverageIgnoreStart', 'codeCoverageIgnoreEnd', 'covers',
        'coversDefaultClass', 'coversNothing', 'dataProvider', 'depends', 'expectedException',
        'expectedExceptionCode', 'expectedExceptionMessage', 'group', 'large', 'medium',
        'preserveGlobalState', 'requires', 'runTestsInSeparateProcesses', 'runInSeparateProcess',
        'small', 'test', 'testdox', 'ticket', 'uses',
    ];

    /**
     * Extra annotations to ignore in addition to standard PHPUnit annotations.
     * 
     * @param array $ignoredAnnotations
     */
    public function _initialize(array $ignoredAnnotations = [])
    {
        parent::_initialize();
        Annotation\AnnotationProvider::registerAnnotationNamespaces();
        // Add standard PHPUnit annotations
        Annotation\AnnotationProvider::addIgnoredAnnotations($this->ignoredAnnotations);
        // Add custom ignored annotations
        Annotation\AnnotationProvider::addIgnoredAnnotations($ignoredAnnotations);
        $outputDirectory = $this->getOutputDirectory();
        $deletePreviousResults =
            $this->tryGetOption(DELETE_PREVIOUS_RESULTS_PARAMETER, false);
        $this->prepareOutputDirectory($outputDirectory, $deletePreviousResults);
        if (is_null(Model\Provider::getOutputDirectory())) {
            Model\Provider::setOutputDirectory($outputDirectory);
        }
    }

    /**
     * Retrieves option or returns default value.
     *
     * @param string $optionKey    Configuration option key.
     * @param mixed  $defaultValue Value to return in case option isn't set.
     *
     * @return mixed Option value.
     * @since 0.1.0
     */
    private function tryGetOption($optionKey, $defaultValue = null)
    {
        if (array_key_exists($optionKey, $this->config)) {
            return $this->config[$optionKey];
        } 
        return $defaultValue;
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * Retrieves option or dies.
     *
     * @param string $optionKey Configuration option key.
     *
     * @throws ConfigurationException Thrown if option can't be retrieved.
     *
     * @return mixed Option value.
     * @since 0.1.0
     */
    private function getOption($optionKey)
    {
        if (!array_key_exists($optionKey, $this->config)) {
            $template = '%s: Couldn\'t find required configuration option `%s`';
            $message = sprintf($template, __CLASS__, $optionKey);
            throw new ConfigurationException($message);
        }
        return $this->config[$optionKey];
    }

    /**
     * Returns output directory.
     *
     * @throws ConfigurationException Thrown if there is Codeception-wide
     *                                problem with output directory
     *                                configuration.
     *
     * @return string Absolute path to output directory.
     * @since 0.1.0
     */
    private function getOutputDirectory()
    {
        $outputDirectory = $this->tryGetOption(
            OUTPUT_DIRECTORY_PARAMETER,
            DEFAULT_RESULTS_DIRECTORY
        );
        $filesystem = new Filesystem;
        if (!$filesystem->isAbsolutePath($outputDirectory)) {
            $outputDirectory = Configuration::outputDir() . $outputDirectory;
        }
        return $outputDirectory;
    }

    /**
     * Creates output directory (if it hasn't been created yet) and cleans it
     * up (if corresponding argument has been set to true).
     *
     * @param string $outputDirectory
     * @param bool   $deletePreviousResults Whether to delete previous results
     *                                      or keep 'em.
     *
     * @since 0.1.0
     */
    private function prepareOutputDirectory(
        $outputDirectory,
        $deletePreviousResults = false
    ) {
        $filesystem = new Filesystem;
        $filesystem->mkdir($outputDirectory, 0775);
        if ($deletePreviousResults) {
            $finder = new Finder;
            $files = $finder->files()->in($outputDirectory)->name('*.xml');
            $filesystem->remove($files);
        }
    }
    
    public function suiteBefore(SuiteEvent $suiteEvent)
    {
        $suite = $suiteEvent->getSuite();
        $suiteName = $suite->getName();
        $event = new TestSuiteStartedEvent($suiteName);
        if (class_exists($suiteName, false)) {
            $annotationManager = new Annotation\AnnotationManager(
                Annotation\AnnotationProvider::getClassAnnotations($suiteName)
            );
            $annotationManager->updateTestSuiteEvent($event);
        }
        $this->uuid = $event->getUuid();
        $this->getLifecycle()->fire($event);
    }

    public function suiteAfter()
    {
        $this->getLifecycle()->fire(new TestSuiteFinishedEvent($this->uuid));
    }

    public function testBefore(TestEvent $testEvent)
    {
        $test = $testEvent->getTest();
        $testName = $test->getName();
        $event = new TestCaseStartedEvent($this->uuid, $testName);
        if ($test instanceof \Codeception\Test\Cest) {
            $testClass = get_class($test->getTestClass());
            if (class_exists($testClass, false)) {
                $annotationManager = new Annotation\AnnotationManager(Annotation\AnnotationProvider::getClassAnnotations($testClass));
                $annotationManager->updateTestCaseEvent($event);
            }
        } else if ($test instanceof \Codeception\Test\Cept) {
            $annotations = $this->getCeptAnnotations($test);
            if (count($annotations) > 0) {
                $annotationManager = new Annotation\AnnotationManager($annotations);
                $annotationManager->updateTestCaseEvent($event);
            }
        }
        
        $this->getLifecycle()->fire($event);
    }
    
    public function testStart(TestEvent $testEvent)
    {
        $test = $testEvent->getTest();
        $testName = $test->getName();
        $event = new TestCaseStartedEvent($this->uuid, $testName);
        if ($test instanceof \Codeception\Test\Cest) {
            $className = get_class($test->getTestClass());
            if (method_exists($className, $testName)){
                $annotationManager = new Annotation\AnnotationManager(Annotation\AnnotationProvider::getMethodAnnotations($className, $testName));
                $annotationManager->updateTestCaseEvent($event);
            }
        } else if ($test instanceof \Codeception\Test\Cept) {
            $annotations = $this->getCeptAnnotations($test);
            if (count($annotations) > 0) {
                $annotationManager = new Annotation\AnnotationManager($annotations);
                $annotationManager->updateTestCaseEvent($event);
            }
        }
        
        $this->getLifecycle()->fire($event);
    }

    /**
     * @param FailEvent $failEvent
     */
    public function testError(FailEvent $failEvent)
    {
        $event = new TestCaseBrokenEvent();
        $e = $failEvent->getFail();
        $message = $e->getMessage();
        $this->getLifecycle()->fire($event->withException($e)->withMessage($message));
    }

    /**
     * @param FailEvent $failEvent
     */
    public function testFail(FailEvent $failEvent)
    {
        $event = new TestCaseFailedEvent();
        $e = $failEvent->getFail();
        $message = $e->getMessage();
        $this->getLifecycle()->fire($event->withException($e)->withMessage($message));
    }

    /**
     * @param FailEvent $failEvent
     */
    public function testIncomplete(FailEvent $failEvent)
    {
        $event = new TestCasePendingEvent();
        $e = $failEvent->getFail();
        $message = $e->getMessage();
        $this->getLifecycle()->fire($event->withException($e)->withMessage($message));
    }

    /**
     * @param FailEvent $failEvent
     */
    public function testSkipped(FailEvent $failEvent)
    {
        $event = new TestCaseCanceledEvent();
        $e = $failEvent->getFail();
        $message = $e->getMessage();
        $this->getLifecycle()->fire($event->withException($e)->withMessage($message));
    }

    public function testEnd()
    {
        $this->getLifecycle()->fire(new TestCaseFinishedEvent());
    }

    public function stepBefore(StepEvent $stepEvent)
    {
        $stepAction = $stepEvent->getStep()->getAction();
        $this->getLifecycle()->fire(new StepStartedEvent($stepAction));
    }

    public function stepAfter()
    {
        $this->getLifecycle()->fire(new StepFinishedEvent());
    }


    /**
     * @return Allure
     */
    public function getLifecycle()
    {
        if (!isset($this->lifecycle)){
            $this->lifecycle = Allure::lifecycle();
        }
        return $this->lifecycle;
    }

    public function setLifecycle(Allure $lifecycle)
    {
        $this->lifecycle = $lifecycle;
    }

    /**
     *
     * @param \Codeception\TestInterface $test
     * @return array
     */
    private function getCeptAnnotations($test)
    {
        $tokens = token_get_all($test->getSourceCode());
        $comments = array();
        $annotations = [];
        foreach($tokens as $token) {
            if($token[0] == T_DOC_COMMENT || $token[0] == T_COMMENT) {
                $comments[] = $token[1];
            }
        }
        foreach($comments as $comment) {
            $lines = preg_split ('/$\R?^/m', $comment);
            foreach($lines as $line) {
                $output = [];
                if (preg_match('/\*\s\@(.*)\((.*)\)/', $line, $output) > 0) {
                    \Codeception\Util\Debug::debug($output);
                    if ($output[1] == "Features") {
                        $feature = new \Yandex\Allure\Adapter\Annotation\Features();
                        $features = $this->splitAnnotationContent($output[2]);
                        foreach($features as $featureName) {
                            $feature->featureNames[] = $featureName;
                        }
                        $annotations[get_class($feature)] = $feature;
                    } else if ($output[1] == 'Title') {
                        $title = new \Yandex\Allure\Adapter\Annotation\Title();
                        $title->value = $output[2];
                        $annotations[get_class($title)] = $title;
                    } else if ($output[1] == 'Description') {
                        $description = new \Yandex\Allure\Adapter\Annotation\Description();
                        $description->value = $output[2];
                        $annotations[get_class($description)] = $description;
                    } else if ($output[1] == 'Stories') {
                        $stories = $this->splitAnnotationContent($output[2]);
                        $story = new \Yandex\Allure\Adapter\Annotation\Stories();
                        foreach($stories as $storyName) {
                            $story->stories[] = $storyName;
                        }
                        $annotations[get_class($story)] = $story;
                    } else if ($output[1] == 'Issues') {
                        $issues = $this->splitAnnotationContent($output[2]);
                        $issue = new \Yandex\Allure\Adapter\Annotation\Stories();
                        foreach($issues as $issueName) {
                            $issues->issuesKeys[] = $issueName;
                        }
                        $annotations[get_class($issue)] = $issue;
                    } else {
                        \Codeception\Util\Debug::debug("Tag not detected: ".$output[1]);
                    }
                }
            }
        }
        return $annotations;
    }

    /**
     *
     * @param string $string
     * @return array
     */
    private function splitAnnotationContent($string)
    {
        $parts = [];
        $detected = str_replace('{', '', $string);
        $detected = str_replace('}', '', $detected);
        $detected = str_replace('"', '', $detected);
        $parts = explode(',', $detected);
        if (count($parts) == 0 && count($detected) > 0) {
            $parts[] = $detected;
        }
        return $parts;
    }

}
