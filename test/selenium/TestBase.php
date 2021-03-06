<?php
/**
 * Base class for Selenium tests
 */
declare(strict_types=1);

namespace PhpMyAdmin\Tests\Selenium;

use Exception;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Exception\InvalidSelectorException;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverSelect;
use InvalidArgumentException;
use mysqli;
use mysqli_result;
use PHPUnit\Framework\TestCase;
use Throwable;
use function curl_close;
use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_init;
use function curl_setopt;
use function getenv;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_strtolower;
use function mb_substr;
use function preg_match;
use function rand;
use function sha1;
use function sprintf;
use function strlen;
use function substr;
use function trim;
use function usleep;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_URL;
use const CURLOPT_USERPWD;
use const PHP_EOL;

/**
 * Base class for Selenium tests.
 *
 * @group      selenium
 */
abstract class TestBase extends TestCase
{
    /** @var RemoteWebDriver */
    protected $webDriver;

    /**
     * mysqli object
     *
     * @access private
     * @var mysqli
     */
    protected $_mysqli;

    /**
     * Name of database for the test
     *
     * @access public
     * @var string
     */
    public $database_name;

    /**
     * The session Id (Browserstack)
     *
     * @var string
     */
    protected $sessionId;

    private const SESSION_REST_URL = 'https://api.browserstack.com/automate/sessions/';

    /**
     * Configures the selenium and database link.
     *
     * @throws Exception
     */
    protected function setUp(): void
    {
        /**
         * Needs to be implemented
         *
         * @ENV TESTSUITE_SELENIUM_COVERAGE
         * @ENV TESTSUITE_FULL
         */
        parent::setUp();

        if (! $this->hasTestSuiteDatabaseServer()) {
            $this->markTestSkipped('Database server is not configured.');
            return;
        }

        try {
            $this->_mysqli = new mysqli(
                $GLOBALS['TESTSUITE_SERVER'],
                $GLOBALS['TESTSUITE_USER'],
                $GLOBALS['TESTSUITE_PASSWORD'],
                'mysql',
                (int) $GLOBALS['TESTSUITE_PORT']
            );
        } catch (Exception $e) {
            // when localhost is used, it tries to connect to a socket and throws and error
            $this->markTestSkipped('Failed to connect to MySQL (' . $e->getMessage() . ')');
            return;
        }

        if ($this->_mysqli->connect_errno) {
            $this->markTestSkipped('Failed to connect to MySQL (' . $this->_mysqli->error . ')');
            return;
        }

        if ($this->getHubUrl() === null) {
            $this->markTestSkipped('Selenium testing is not configured.');
            return;
        }

        $capabilities = $this->getCapabilities();
        $this->addCapabilities($capabilities);
        $url = $this->getHubUrl();

        $this->webDriver = RemoteWebDriver::create(
            $url,
            $capabilities
        );

        $this->sessionId = $this->webDriver->getSessionId();

        $this->database_name = $GLOBALS['TESTSUITE_DATABASE']
            . mb_substr(sha1((string) rand()), 0, 7);
        $this->dbQuery(
            'CREATE DATABASE IF NOT EXISTS ' . $this->database_name
        );
        $this->dbQuery(
            'USE ' . $this->database_name
        );

        $this->navigateTo('');
        $this->webDriver->manage()->window()->maximize();
    }

    /**
     * Has CI config ( CI_MODE == selenium )
     */
    public function hasCIConfig(): bool
    {
        if (empty($GLOBALS['CI_MODE'])) {
            return false;
        }
        return $GLOBALS['CI_MODE'] == 'selenium';
    }

    /**
     * Has ENV variables set for Browserstack
     */
    public function hasBrowserstackConfig(): bool
    {
        return ! empty($GLOBALS['TESTSUITE_BROWSERSTACK_USER'])
            && ! empty($GLOBALS['TESTSUITE_BROWSERSTACK_KEY']);
    }

    /**
     * Has ENV variables set for local Selenium server
     */
    public function hasSeleniumConfig(): bool
    {
        return ! empty($GLOBALS['TESTSUITE_SELENIUM_HOST'])
            && ! empty($GLOBALS['TESTSUITE_SELENIUM_PORT']);
    }

    /**
     * Get hub url
     */
    public function getHubUrl(): ?string
    {
        if ($this->hasBrowserstackConfig()) {
            return 'https://'
            . $GLOBALS['TESTSUITE_BROWSERSTACK_USER'] . ':'
            . $GLOBALS['TESTSUITE_BROWSERSTACK_KEY'] .
            '@hub-cloud.browserstack.com/wd/hub';
        } elseif ($this->hasSeleniumConfig()) {
            return 'http://'
            . $GLOBALS['TESTSUITE_SELENIUM_HOST'] . ':'
            . $GLOBALS['TESTSUITE_SELENIUM_PORT'] . '/wd/hub';
        } else {
            return null;
        }
    }

    /**
     * Has TESTSUITE_SERVER, TESTSUITE_USER and TESTSUITE_DATABASE variables set
     */
    public function hasTestSuiteDatabaseServer(): bool
    {
        return ! empty($GLOBALS['TESTSUITE_SERVER'])
            && ! empty($GLOBALS['TESTSUITE_USER'])
            && ! empty($GLOBALS['TESTSUITE_DATABASE']);
    }

    /**
     * Navigate to URL
     *
     * @param string $url The URL
     */
    private function navigateTo(string $url): void
    {
        if (substr($GLOBALS['TESTSUITE_URL'], -1) === '/') {
            $url = $GLOBALS['TESTSUITE_URL'] . $url;
        } else {
            $url = $GLOBALS['TESTSUITE_URL'] . '/' . $url;
        }

        $this->webDriver->get($url);
    }

    /**
     * Add specific capabilities
     *
     * @param DesiredCapabilities $capabilities The capabilities object
     */
    public function addCapabilities(DesiredCapabilities $capabilities): void
    {
        $buildLocal = true;
        $buildId = 'Manual';
        $projectName = 'phpMyAdmin';

        if (getenv('BUILD_TAG')) {
            $buildId = getenv('BUILD_TAG');
            $buildLocal = false;
            $projectName = 'phpMyAdmin (Jenkins)';
        } elseif (getenv('TRAVIS_JOB_NUMBER')) {
            $buildId = 'travis-' . getenv('TRAVIS_JOB_NUMBER');
            $buildLocal = true;
            $projectName = 'phpMyAdmin (Travis)';
        }

        $capabilities->setCapability('project', $projectName);
        $capabilities->setCapability('build', $buildId);
        $capabilities->setCapability('browserstack.debug', false);

        /**
         * Usefull for browserstack
         *
         * @see https://github.com/phpmyadmin/phpmyadmin/pull/14595#issuecomment-418541475
         * Reports the name of the test to browserstack
         */
        $className = substr(static::class, strlen('PhpMyAdmin\Tests\Selenium\\'));
        $capabilities->setCapability(
            'name',
            $className . ': ' . $this->getName()
        );

        if ($buildLocal) {
            $capabilities->setCapability(
                'browserstack.local',
                $buildLocal
            );
            $capabilities->setCapability(
                'browserstack.localIdentifier',
                $buildId
            );
            $capabilities->setCapability(
                'browserstack.debug',
                true
            );
            $capabilities->setCapability(
                'browserstack.console',
                'verbose'
            );

            $capabilities->setCapability(
                'browserstack.networkLogs',
                true
            );
        }
    }

    /**
     * Get basic capabilities
     */
    public function getCapabilities(): DesiredCapabilities
    {
        switch ($GLOBALS['TESTSUITE_SELENIUM_BROWSER']) {
            case 'chrome':
            default:
                $capabilities = DesiredCapabilities::chrome();
                $chromeOptions = new ChromeOptions();
                $chromeOptions->addArguments([
                    '--lang=en',
                ]);
                $capabilities->setCapability(
                    ChromeOptions::CAPABILITY_W3C,
                    $chromeOptions
                );
                $capabilities->setCapability(
                    'loggingPrefs',
                    ['browser' => 'ALL']
                );

                if ($this->hasCIConfig() && $this->hasBrowserstackConfig()) {
                    $capabilities->setCapability(
                        'os',
                        'Windows' // Force windows
                    );
                    $capabilities->setCapability(
                        'os_version',
                        '10' // Force windows 10
                    );
                    $capabilities->setCapability(
                        'browser_version',
                        '69.0' // Force chrome 69.0
                    );
                }

                return $capabilities;
            case 'safari':
                $capabilities = DesiredCapabilities::safari();
                if ($this->hasCIConfig() && $this->hasBrowserstackConfig()) {
                    $capabilities->setCapability(
                        'os',
                        'OS X' // Force OS X
                    );
                    $capabilities->setCapability(
                        'os_version',
                        'Sierra' // Force OS X Sierra
                    );
                    $capabilities->setCapability(
                        'browser_version',
                        '10.1' // Force Safari 10.1
                    );
                }
                return $capabilities;
            case 'edge':
                $capabilities = DesiredCapabilities::microsoftEdge();
                if ($this->hasCIConfig() && $this->hasBrowserstackConfig()) {
                    $capabilities->setCapability(
                        'os',
                        'Windows' // Force windows
                    );
                    $capabilities->setCapability(
                        'os_version',
                        '10' // Force windows 10
                    );
                    $capabilities->setCapability(
                        'browser_version',
                        'insider preview' // Force Edge insider preview
                    );
                }
                return $capabilities;
        }
    }

    /**
     * Checks whether user is a superuser.
     *
     * @return bool
     */
    protected function isSuperUser()
    {
        $result = $this->dbQuery('SELECT COUNT(*) FROM mysql.user');
        if ($result !== false) {
            $result->free();
            return true;
        }
        return false;
    }

    /**
     * Skips test if test user is not a superuser.
     *
     * @return void
     */
    protected function skipIfNotSuperUser()
    {
        if (! $this->isSuperUser()) {
            $this->markTestSkipped('Test user is not a superuser.');
        }
    }

    /**
     * Skips test if pmadb is not configured.
     *
     * @return void
     */
    protected function skipIfNotPMADB()
    {
        $this->navigateTo('index.php?route=/check-relations');
        $pageContent = $this->waitForElement('id', 'page_content');
        if (preg_match(
            '/Configuration of pmadb… not OK/i',
            $pageContent->getText()
        )) {
            $this->markTestSkipped(
                'The phpMyAdmin configuration storage is not working.'
            );
        }
    }

    /**
     * perform a login
     *
     * @param string $username Username
     * @param string $password Password
     *
     * @return void
     */
    public function login($username = '', $password = '')
    {
        if ($username == '') {
            $username = $GLOBALS['TESTSUITE_USER'];
        }
        if ($password == '') {
            $password = $GLOBALS['TESTSUITE_PASSWORD'];
        }
        $this->navigateTo('');
        /* Wait while page */
        while ($this->webDriver->executeScript(
            'return document.readyState !== "complete";'
        )) {
            usleep(5000);
        }

        /* Return if already logged in */
        if ($this->isSuccessLogin()) {
            return;
        }

        // Clear the input for Microsoft Edge (remebers the username)
        $this->waitForElement('id', 'input_username')->clear()->click()->sendKeys($username);
        $this->byId('input_password')->click()->sendKeys($password);
        $this->byId('input_go')->click();
    }

    /**
     * Get element by Id
     *
     * @param string $id The element ID
     */
    public function byId(string $id): WebDriverElement
    {
        return $this->webDriver->findElement(WebDriverBy::id($id));
    }

    /**
     * Get element by css selector
     *
     * @param string $selector The element css selector
     */
    public function byCssSelector(string $selector): WebDriverElement
    {
        return $this->webDriver->findElement(WebDriverBy::cssSelector($selector));
    }

    /**
     * Get element by xpath
     *
     * @param string $xpath The xpath
     */
    public function byXPath(string $xpath): WebDriverElement
    {
        return $this->webDriver->findElement(WebDriverBy::xpath($xpath));
    }

    /**
     * Get element by linkText
     *
     * @param string $linkText The link text
     */
    public function byLinkText(string $linkText): WebDriverElement
    {
        return $this->webDriver->findElement(WebDriverBy::linkText($linkText));
    }

    /**
     * Double click
     */
    public function doubleclick(): void
    {
        $this->webDriver->action()->doubleClick()->perform();
    }

    /**
     * Simple click
     */
    public function click(): void
    {
        $this->webDriver->action()->click()->perform();
    }

    /**
     * Get element by byPartialLinkText
     *
     * @param string $partialLinkText The partial link text
     */
    public function byPartialLinkText(string $partialLinkText): WebDriverElement
    {
        return $this->webDriver->findElement(WebDriverBy::partialLinkText($partialLinkText));
    }

    /**
     * Returns true if the browser is safari
     */
    public function isSafari(): bool
    {
        return mb_strtolower($this->webDriver->getCapabilities()->getBrowserName()) === 'safari';
    }

    /**
     * Get element by name
     *
     * @param string $name The name
     */
    public function byName(string $name): WebDriverElement
    {
        return $this->webDriver->findElement(WebDriverBy::name($name));
    }

    /**
     * Checks whether the login is successful
     *
     * @return bool
     */
    public function isSuccessLogin()
    {
        return $this->isElementPresent('xpath', '//*[@id="server-breadcrumb"]');
    }

    /**
     * Checks whether the login is unsuccessful
     *
     * @return bool
     */
    public function isUnsuccessLogin()
    {
        return $this->isElementPresent('cssSelector', 'div.error');
    }

    /**
     * Used to go to the homepage
     *
     * @return void
     */
    public function gotoHomepage()
    {
        $e = $this->byPartialLinkText('Server: ');
        $e->click();
        $this->waitAjax();
    }

    /**
     * webDriver->executeScripts a database query
     *
     * @param string $query SQL Query to be webDriver->executeScriptd
     *
     * @return void|bool|mysqli_result
     *
     * @throws Exception
     */
    public function dbQuery($query)
    {
        return $this->_mysqli->query($query);
    }

    /**
     * Check if user is logged in to phpmyadmin
     *
     * @return bool Where or not user is logged in
     */
    public function isLoggedIn()
    {
        return $this->isElementPresent(
            'xpath',
            '//*[@id="server-breadcrumb"]/a[1]'
        );
    }

    /**
     * Perform a logout, if logged in
     *
     * @return void
     */
    public function logOutIfLoggedIn()
    {
        if ($this->isLoggedIn()) {
            $this->byCssSelector('img.icon.ic_s_loggoff')->click();
        }
    }

    /**
     * Wait for an element to be present on the page
     *
     * @param string $func Locate using - cssSelector, xpath, tagName, partialLinkText, linkText, name, id, className
     * @param string $arg  Selector
     *
     * @return WebDriverElement Element waited for
     */
    public function waitForElement(string $func, $arg): WebDriverElement
    {
        return $this->webDriver->wait(30, 500)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::$func($arg))
        );
    }

    /**
     * Wait for an element to be present on the page or timeout
     *
     * @param string $func    Locate using - cssSelector, xpath, tagName, partialLinkText, linkText, name, id, className
     * @param string $arg     Selector
     * @param int    $timeout Timeout in seconds
     */
    public function waitUntilElementIsPresent(string $func, $arg, int $timeout): WebDriverElement
    {
        return $this->webDriver->wait($timeout, 500)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::$func($arg))
        );
    }

    /**
     * Wait for an element to be visible on the page or timeout
     *
     * @param string $func    Locate using - cssSelector, xpath, tagName, partialLinkText, linkText, name, id, className
     * @param string $arg     Selector
     * @param int    $timeout Timeout in seconds
     */
    public function waitUntilElementIsVisible(string $func, $arg, int $timeout): WebDriverElement
    {
        return $this->webDriver->wait($timeout, 500)->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::$func($arg))
        );
    }

    /**
     * Wait for an element to disappear
     *
     * @param string $func Locate using - byCss, byXPath, etc
     * @param string $arg  Selector
     *
     * @return bool Whether or not the element disappeared
     */
    public function waitForElementNotPresent($func, $arg)
    {
        while (true) {
            if (! $this->isElementPresent($func, $arg)) {
                return true;
            }
            usleep(5000);
        }
    }

    /**
     * Check if element is present or not
     *
     * @param string $func Locate using - cssSelector, xpath, tagName, partialLinkText, linkText, name, id, className
     * @param string $arg  Selector
     *
     * @return bool Whether or not the element is present
     */
    public function isElementPresent(string $func, $arg): bool
    {
        try {
            $this->webDriver->findElement(WebDriverBy::$func($arg));
        } catch (NoSuchElementException $e) {
            // Element not present
            return false;
        } catch (InvalidArgumentException $e) {
            // Element not present
            return false;
        } catch (InvalidSelectorException $e) {
            // Element not present
            return false;
        }
        // Element Present
        return true;
    }

    /**
     * Get table cell data by the ID of the table
     *
     * @param string $tableID Table identifier
     * @param int    $row     Table row
     * @param int    $column  Table column
     *
     * @return string text Data from the particular table cell
     */
    public function getCellByTableId($tableID, $row, $column)
    {
        $sel = sprintf(
            'table#%s tbody tr:nth-child(%d) td:nth-child(%d)',
            $tableID,
            $row,
            $column
        );
        $element = $this->byCssSelector(
            $sel
        );
        $text = $element->getText();

        return $text && is_string($text) ? trim($text) : '';
    }

    /**
     * Get table cell data by the class attribute of the table
     *
     * @param string $tableClass Class of the table
     * @param int    $row        Table row
     * @param int    $column     Table column
     *
     * @return string text Data from the particular table cell
     */
    public function getCellByTableClass($tableClass, $row, $column)
    {
        $sel = sprintf(
            'table.%s tbody tr:nth-child(%d) td:nth-child(%d)',
            $tableClass,
            $row,
            $column
        );
        $element = $this->byCssSelector(
            $sel
        );
        $text = $element->getText();

        return $text && is_string($text) ? trim($text) : '';
    }

    /**
     * Wrapper around keys method to not use it on not supported
     * browsers.
     *
     * @param string $text Keys to send
     */
    public function keys(string $text): void
    {
        /**
         * Not supported in Safari Webdriver, see
         * https://github.com/seleniumhq/selenium-google-code-issue-archive/issues/4136
         */
        if ($this->isSafari()) {
            $this->markTestSkipped('Can not send keys to Safari browser.');
        } else {
            $this->webDriver->getKeyboard()->sendKeys($text);
        }
    }

    /**
     * Wrapper around moveto method to not use it on not supported
     * browsers.
     *
     * @param RemoteWebElement $element element
     */
    public function moveto(RemoteWebElement $element): void
    {
        /**
         * Not supported in Safari Webdriver, see
         * https://github.com/seleniumhq/selenium-google-code-issue-archive/issues/4136
         */
        if ($this->isSafari()) {
            $this->markTestSkipped('MoveTo not supported on Safari browser.');
        } else {
            $this->webDriver->getMouse()->mouseMove($element->getCoordinates());
        }
    }

    /**
     * Wrapper around alertText method to not use it on not supported
     * browsers.
     *
     * @return mixed
     */
    public function alertText()
    {
        /**
         * Not supported in Safari Webdriver, see
         * https://github.com/seleniumhq/selenium-google-code-issue-archive/issues/4136
         */
        if ($this->isSafari()) {
            $this->markTestSkipped('Alerts not supported on Safari browser.');
        } else {
            return $this->webDriver->switchTo()->alert()->getText();
        }
    }

    /**
     * Type text in textarea (CodeMirror enabled)
     *
     * @param string $text  Text to type
     * @param int    $index Index of CodeMirror instance to write to
     *
     * @return void
     */
    public function typeInTextArea($text, $index = 0)
    {
        $this->waitForElement('cssSelector', 'div.cm-s-default');
        $this->webDriver->executeScript(
            "$('.cm-s-default')[" . $index . "].CodeMirror.setValue('" . $text . "');"
        );
    }

    /**
     * Accept alert
     */
    public function acceptAlert(): void
    {
        $this->webDriver->switchTo()->alert()->accept();
    }

    /**
     * Kills the More link in the menu
     *
     * @return void
     */
    public function expandMore()
    {
        try {
            $ele = $this->waitForElement('cssSelector', 'li.submenu > a');

            $ele->click();
            $this->waitForElement('cssSelector', 'li.submenuhover > a');

            $this->waitUntilElementIsPresent(
                'cssSelector',
                'li.submenuhover.submenu.shown',
                5000
            );
        } catch (WebDriverException $e) {
            return;
        }
    }

    /**
     * Navigates browser to a table page.
     *
     * @param string $table Name of table
     *
     * @return void
     */
    public function navigateTable($table)
    {
        $this->navigateDatabase($this->database_name);

        // go to table page
        $this->waitForElement(
            'xpath',
            "//th//a[contains(., '" . $table . "')]"
        )->click();
        $this->waitAjax();

        $this->waitForElement(
            'xpath',
            "//a[@class='nav-link text-nowrap' and contains(., 'Browse')]"
        );
    }

    /**
     * Navigates browser to a database page.
     *
     * @param string $database             Name of database
     * @param bool   $gotoHomepageRequired Go to homepage required
     *
     * @return void
     */
    public function navigateDatabase($database, $gotoHomepageRequired = false)
    {
        if ($gotoHomepageRequired) {
            $this->gotoHomepage();
        }

        // Go to server databases
        $this->waitForElement('partialLinkText', 'Databases')->click();
        $this->waitAjax();

        // go to specific database page
        $this->waitForElement(
            'xpath',
            '//tr[(contains(@class, "db-row"))]//a[contains(., "' . $this->database_name . '")]'
        )->click();
        $this->waitAjax();

        // Wait for it to load
        $this->waitForElement(
            'xpath',
            "//a[@class='nav-link text-nowrap' and contains(., 'Structure')]"
        );
    }

    /**
     * Select an option that matches a value
     *
     * @param WebDriverElement $element The element
     * @param string           $value   The value of the option
     */
    public function selectByValue(WebDriverElement $element, string $value): void
    {
        $select = new WebDriverSelect($element);
        $select->selectByValue($value);
    }

    /**
     * Select an option that matches a text
     *
     * @param WebDriverElement $element The element
     * @param string           $text    The text
     */
    public function selectByLabel(WebDriverElement $element, string $text): void
    {
        $select = new WebDriverSelect($element);
        $select->selectByVisibleText($text);
    }

    /**
     * Scrolls to a coordinate such that the element with given id is visible
     *
     * @param string $element_id Id of the element
     * @param int    $y_offset   Offset from Y-coordinate of element
     *
     * @return void
     */
    public function scrollIntoView($element_id, $y_offset = 70)
    {
        // 70pt offset by-default so that the topmenu does not cover the element
        $this->webDriver->executeScript(
            'var position = document.getElementById("'
            . $element_id . '").getBoundingClientRect();'
            . 'window.scrollBy(0, position.top-(' . $y_offset . '));'
        );
    }

    /**
     * Scrolls to a coordinate such that the element
     *
     * @param WebDriverElement $element The element
     * @param int              $xOffset The x offset to apply (defaults to 0)
     * @param int              $yOffset The y offset to apply (defaults to 0)
     *
     * @return void
     */
    public function scrollToElement(WebDriverElement $element, int $xOffset = 0, int $yOffset = 0): void
    {
        $this->webDriver->executeScript(
            'window.scrollBy(' . ($element->getLocation()->getX() + $xOffset) . ', ' . ($element->getLocation()->getY() + $yOffset) . ');'
        );
    }

    /**
     * Scroll to the bottom of page
     *
     * @return void
     */
    public function scrollToBottom(): void
    {
        $this->webDriver->executeScript(
            'window.scrollTo(0,document.body.scrollHeight);'
        );
    }

    /**
     * Reload the page
     *
     * @return void
     */
    public function reloadPage(): void
    {
        $this->webDriver->executeScript(
            'window.location.reload();'
        );
    }

    /**
     * Wait for AJAX completion
     *
     * @return void
     */
    public function waitAjax()
    {
        /* Wait while code is loading */
        while ($this->webDriver->executeScript(
            'return AJAX.active;'
        )) {
            usleep(5000);
        }
    }

    /**
     * Wait for AJAX message disappear
     *
     * @return void
     */
    public function waitAjaxMessage()
    {
        /* Get current message count */
        $ajax_message_count = $this->webDriver->executeScript(
            'return ajaxMessageCount;'
        );
        /* Ensure the popup is gone */
        $this->waitForElementNotPresent(
            'id',
            'ajax_message_num_' . $ajax_message_count
        );
    }

    /**
     * Tear Down function for test cases
     */
    protected function tearDown(): void
    {
        if ($this->_mysqli != null) {
            $this->dbQuery('DROP DATABASE IF EXISTS ' . $this->database_name);
            $this->_mysqli->close();
            $this->_mysqli = null;
        }
        if (! $this->hasFailed()) {
            $this->markTestAs('passed', '');
        }
        $this->webDriver->quit();
    }

    /**
     * Mark test as failed or passed on BrowserStack
     *
     * @param string $status  passed or failed
     * @param string $message a message
     * @return void
     */
    private function markTestAs(string $status, string $message): void
    {
        // If this is being run on Browerstack,
        // mark the test on Browerstack as failure
        if ($this->hasBrowserstackConfig()) {
            $payload = json_encode(
                [
                    'status' => $status,
                    'reason' => $message,
                ]
            );

            $ch = curl_init();
            curl_setopt(
                $ch,
                CURLOPT_URL,
                self::SESSION_REST_URL . $this->sessionId . '.json'
            );
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt(
                $ch,
                CURLOPT_USERPWD,
                $GLOBALS['TESTSUITE_BROWSERSTACK_USER']
                    . ':' . $GLOBALS['TESTSUITE_BROWSERSTACK_KEY']
            );

            $headers = [];
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            curl_exec($ch);
            if ($ch !== false && curl_errno($ch)) {
                echo 'Error: ' . curl_error($ch) . PHP_EOL;
            }
            curl_close($ch);
        }
    }

    /**
     * Mark unsuccessful tests as 'Failures' on Browerstack
     *
     * @param Throwable $t Throwable
     *
     * @return void
     */
    public function onNotSuccessfulTest(Throwable $t): void
    {
        // End testing session
        if ($this->webDriver !== null) {
            $this->webDriver->quit();
        }
        $this->markTestAs('failed', $t->getMessage());

        if ($this->hasBrowserstackConfig()) {
            $ch = curl_init();
            if ($ch !== false) {
                curl_setopt(
                    $ch,
                    CURLOPT_URL,
                    self::SESSION_REST_URL . $this->sessionId . '.json'
                );
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt(
                    $ch,
                    CURLOPT_USERPWD,
                    $GLOBALS['TESTSUITE_BROWSERSTACK_USER']
                    . ':' . $GLOBALS['TESTSUITE_BROWSERSTACK_KEY']
                );
                $result = curl_exec($ch);
                $proj = json_decode($result);
                if (isset($proj->automation_session)) {
                    echo 'Test failed, get more information here: ' . $proj->automation_session->public_url . PHP_EOL;
                }
                if ($ch !== false && curl_errno($ch)) {
                    echo 'Error: ' . curl_error($ch) . PHP_EOL;
                }
                curl_close($ch);
            } else {
                echo 'Error: curl_init' . PHP_EOL;
            }
        }

        // Call parent's onNotSuccessful to handle everything else
        parent::onNotSuccessfulTest($t);
    }
}
