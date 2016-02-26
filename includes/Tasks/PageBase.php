<?php

namespace Waca\Tasks;

use Exception;
use SessionAlert;
use TransactionException;
use User;
use Waca\Exceptions\ApplicationLogicException;
use Waca\Fragments\TemplateOutput;
use Waca\WebRequest;

abstract class PageBase extends TaskBase implements IRoutedTask
{
	use TemplateOutput;

	/** @var string Smarty template to display */
	protected $template = "base.tpl";
	/** @var string HTML title. Currently unused. */
	protected $htmlTitle;
	/** @var bool Determines if the page is a redirect or not */
	protected $isRedirecting = false;
	/** @var array Queue of headers to be sent on successful completion */
	protected $headerQueue = array();
	/** @var string The name of the route to use, as determined by the request router. */
	private $routeName = null;

	/**
	 * Sets the route the request will take. Only should be called from the request router.
	 *
	 * @param $routeName string
	 *
	 * @throws Exception
	 * @category Security-Critical
	 */
	final public function setRoute($routeName)
	{
		// Test the new route is callable before adopting it.
		if (!is_callable(array($this, $routeName))) {
			throw new Exception("Proposed route '$routeName' is not callable.");
		}

		// Adopt the new route
		$this->routeName = $routeName;
	}

	/**
	 * Gets the name of the route that has been passed from the request router.
	 * @return string
	 */
	final public function getRouteName()
	{
		return $this->routeName;
	}

	/**
	 * Performs generic page setup actions
	 */
	final protected function setupPage()
	{
		$this->setUpSmarty();
	}

	/**
	 * Runs the page logic as routed by the RequestRouter
	 *
	 * Only should be called after a security barrier! That means only from execute().
	 */
	final protected function runPage()
	{
		$database = $this->getDatabase();

		// initialise a database transaction
		if (!$database->beginTransaction()) {
			throw new Exception('Failed to start transaction on primary database.');
		}

		try {
			// run the page code
			$this->{$this->getRouteName()}();

			$database->commit();
		}
		catch (TransactionException $ex) {
			$database->rollBack();
			throw $ex;
		}
		catch (ApplicationLogicException $ex) {
			// it's an application logic exception, so nothing went seriously wrong with the site. We can use the
			// standard templating system for this.

			// Firstly, let's undo anything that happened to the database.
			$database->rollBack();

			// Reset smarty
			$this->setUpSmarty();

			// Set the template
			$this->setTemplate("exception/application-logic.tpl");
			$this->assign('message', $ex->getMessage());

			// Force this back to false
			$this->isRedirecting = false;
			$this->headerQueue = array();
		}
		finally {
			// Catch any hanging on transactions
			if ($database->hasActiveTransaction()) {
				$database->rollBack();
			}
		}

		// run any finalisation code needed before we send the output to the browser.
		$this->finalisePage();

		// Send the headers
		foreach ($this->headerQueue as $item) {
			header($item);
		}

		// Check we have a template to use!
		if ($this->template !== null) {
			$content = $this->fetchTemplate($this->template);
			ob_clean();
			print($content);
			ob_flush();

			return;
		}
	}

	/**
	 * Performs final tasks needed before rendering the page.
	 */
	final protected function finalisePage()
	{
		if ($this->isRedirecting) {
			$this->template = null;

			return;
		}

		if (User::getCurrent($this->getDatabase())->isNew()) {
			$registeredSuccessfully = new SessionAlert(
				'Your request will be reviewed soon by a tool administrator, and you\'ll get an email informing you of the decision. You won\'t be able to access most of the tool until then.',
				'Account Requested!', 'alert-success', false);
			SessionAlert::append($registeredSuccessfully);
		}

		// If we're actually displaying content, we want to add the session alerts here!
		$this->assign("alerts", SessionAlert::getAlerts());
		SessionAlert::clearAlerts();

		$this->assign("htmlTitle", $this->htmlTitle);

		$this->assign("typeAheadBlock", $this->getTypeAheadHelper()->getTypeAheadScriptBlock());
	}

	/**
	 * Sends the redirect headers to perform a GET at the destination page.
	 *
	 * Also nullifies the set template so Smarty does not render it.
	 *
	 * @param string      $page   The page to redirect requests to (as used in the UR)
	 * @param null|string $action The action to use on the page.
	 * @param null|array  $parameters
	 */
	final protected function redirect($page = '', $action = null, $parameters = null)
	{
		$pathInfo = array(WebRequest::scriptName());

		$pathInfo[1] = $page;

		if ($action !== null) {
			$pathInfo[2] = $action;
		}

		$url = implode("/", $pathInfo);

		if (is_array($parameters) && count($parameters) > 0) {
			$url .= '?' . http_build_query($parameters);
		}

		$this->redirectUrl($url);
	}

	/**
	 * Sends the redirect headers to perform a GET at the new address.
	 *
	 * Also nullifies the set template so Smarty does not render it.
	 *
	 * @param string $path URL to redirect to
	 */
	final protected function redirectUrl($path)
	{
		// 303 See Other = re-request at new address with a GET.
		$this->headerQueue[] = "HTTP/1.1 303 See Other";
		$this->headerQueue[] = "Location: $path";

		$this->setTemplate(null);
		$this->isRedirecting = true;
	}

	/**
	 * Sets the name of the template this page should display.
	 *
	 * @param string $name
	 *
	 * @throws Exception
	 */
	final protected function setTemplate($name)
	{
		if ($this->isRedirecting) {
			throw new Exception('This page has been set as a redirect, no template can be displayed!');
		}

		$this->template = $name;
	}

	/**
	 * Main function for this page, when no specific actions are called.
	 * @return void
	 */
	abstract protected function main();

	/**
	 * @param string $title
	 */
	final protected function setHtmlTitle($title)
	{
		$this->htmlTitle = $title;
	}

	public function execute()
	{
		if ($this->getRouteName() === null) {
			throw new Exception("Request is unrouted.");
		}

		if ($this->getSiteConfiguration() === null) {
			throw new Exception("Page has no configuration!");
		}

		$this->setupPage();

		$this->runPage();
	}
}