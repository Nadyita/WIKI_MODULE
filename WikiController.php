<?php

namespace Budabot\User\Modules;

use StdClass;
use JsonException;
use Budabot\Core\CommandReply;

/**
 * Authors:
 *	- Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'wiki',
 *		accessLevel = 'all',
 *		description = 'Look up a word in Wikipedia',
 *		help        = 'wiki.txt'
 *	)
 */
class WikiController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 * @var string $moduleName
	 */
	public $moduleName;

	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;

	/**
	 * @var \Budabot\Core\Http $http
	 * @Inject
	 */
	public $http;

	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;

	/**
	 * Command to look up wiki entries
	 *
	 * @param string                     $message The full command received
	 * @param string                     $channel Where did the command come from (tell, guild, priv)
	 * @param string                     $sender  The name of the user issuing the command
	 * @param \Budabot\Core\CommandReply $sendto  Object to use to reply to
	 * @param string[]                   $args    The arguments to the dict-command
	 * @return void
	 *
	 * @HandlesCommand("wiki")
	 * @Matches("/^wiki\s+(.+)$/i")
	 */
	public function wikiCommand($message, $channel, $sender, $sendto, $args) {
		$this->http
			->get('https://en.wikipedia.org/w/api.php')
			->withQueryParams([
				'format' => 'json',
				'action' => 'query',
				'prop' => 'extracts',
				'exintro' => 1,
				'explaintext' => 1,
				'redirects' => 1,
				'titles' => str_replace("&#39;", "'", $args[1]),
			])
			->withTimeout(5)
			->withCallback(function($response) use ($sendto) {
				$this->handleExtractResponse($response, $sendto);
			});
	}

	/**
	 * Handle the response for a list of links origination from a page
	 *
	 * @param \StdClass                  $response The HTTP response object
	 * @param \Budabot\Core\CommandReply $sendto   Object to send the reply to
	 * @return void
	 */
	public function handleLinksResponse(StdClass $response, CommandReply $sendto): void {
		$page = $this->parseResponseIntoWikiPage($response, $sendto);
		if ($page === null) {
			return;
		}
		$blobs = array_map(
			function($link) {
				return $this->text->makeChatCmd($link['title'], '/tell <myname> wiki ' . $link['title']);
			},
			$page->links
		);
		$blob = join("\n", $blobs);
		$msg = $this->text->makeBlob($page->title . ' (disambiguation)', $blob);
		$sendto->reply($msg);
	}

	/**
	 * Handle the response for a wiki page
	 *
	 * @param \StdClass                  $response The HTTP response object
	 * @param \Budabot\Core\CommandReply $sendto   Object to send the reply to
	 * @return void
	 */
	public function handleExtractResponse(StdClass $response, CommandReply $sendto): void {
		$page = $this->parseResponseIntoWikiPage($response, $sendto);
		if ($page === null) {
			return;
		}

		// In case we have a page that gives us a list of terms, but no exact match,
		// query for all links in that page and present them
		if (preg_match('/may refer to:$/', $page->extract)) {
			$this->http
				->get('https://en.wikipedia.org/w/api.php')
				->withQueryParams([
					'format' => 'json',
					'action' => 'query',
					'prop' => 'links',
					'pllimit' => 'max',
					'redirects' => 1,
					'plnamespace' => 0,
					'titles' => $page->title,
				])
				->withTimeout(5)
				->withCallback(function($response) use ($sendto) {
					$this->handleLinksResponse($response, $sendto);
				});
			return;
		}
		$blob = $page->extract;
		$blob = preg_replace('/([a-z0-9])\.([A-Z])/', '$1. $2', $blob);
		$msg = $this->text->makeBlob($page->title, $blob);
		$sendto->reply($msg);
	}

	/**
	 * Parse the AsyncHttp reply into a WikiPage object or null on error
	 *
	 * @param \StdClass $response The reponse object from AsyncHttp
	 * @param \Budabot\Core\CommandReply $sendto Where to send to all errors to
	 * @return \Budabot\User\Modules\WikiPage|null The parsed wiki page or null on error
	 */
	protected function parseResponseIntoWikiPage(StdClass $response, CommandReply $sendto): ?WikiPage {
		if (isset($response->error)) {
			$msg = "There was an error getting data from Wikipedia: ".
				$response->error.
				". Please try again later.";
			$sendto->reply($msg);
			return null;
		}
		try {
			$wikiData = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			$msg = "Unable to parse Wikipedia's reply.";
			$sendto->reply($msg);
			return null;
		}
		$pageID = array_keys($wikiData['query']['pages'])[0];
		$page = $wikiData['query']['pages'][$pageID];
		if ($pageID === "-1") {
			$msg = "Couldn't find a Wikipedia entry for <highlight>" . $page['title'] . "<end>.";
			$sendto->reply($msg);
			return null;
		}
		$wikiPage = new WikiPage();
		$wikiPage->title = $page['title'];
		if (array_key_exists('links', $page)) {
			$wikiPage->links = $page['links'];
		}
		if (array_key_exists('extract', $page)) {
			$wikiPage->extract = $page['extract'];
		}
		return $wikiPage;
	}
}

class WikiPage {
	public $title;
	public $extract;
	public $links;
}

