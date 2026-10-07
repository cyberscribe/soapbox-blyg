<?php
/**
 * The templated layout's document routes.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Surface;

use SoapboxBlyg\Origins\Origin;
use SoapboxBlyg\Origins\Registry;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the feed, the archive index, item documents and pinned versions of a templated origin
 * from REST routes. The manifest names these URLs and readers follow it (spec §16.6e), so the
 * documents can sit where WordPress routes natively, away from the .json and .xml paths that
 * managed hosts treat as static files and cache at the edge.
 *
 * The routes are REST routes in routing only. What they return is the document's exact bytes:
 * no envelope, no _links, and the same headers the rewrite path sends, because the bytes and the
 * headers are what the protocol fixes. That is done by answering in `rest_pre_serve_request`,
 * where the REST server asks whether anyone else wants to write the response.
 */
class RestDocuments {

	/**
	 * Marks a REST response as carrying a surface response for Http::send().
	 */
	const MARKER = 'soapbox_blyg_surface';

	/**
	 * The registry.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * The documents.
	 *
	 * @var Documents
	 */
	private $documents;

	/**
	 * The router, for the id and version checks it shares with the default paths.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Constructor.
	 *
	 * @param Registry  $registry  Origin registry.
	 * @param Documents $documents The documents.
	 * @param Router    $router    The router.
	 */
	public function __construct( Registry $registry, Documents $documents, Router $router ) {
		$this->registry  = $registry;
		$this->documents = $documents;
		$this->router    = $router;
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve' ), 10, 3 );
		// The REST server answers OPTIONS itself, with a description of the route. A reader's
		// preflight needs the surface's CORS answer instead, so ours is put in first.
		add_filter( 'rest_pre_dispatch', array( $this, 'preflight' ), 10, 3 );
	}

	/**
	 * Registers the routes. The id and version segments accept anything and are validated by the
	 * callbacks, so that a malformed one gets the surface's own 404 rather than the REST server's
	 * JSON error.
	 */
	public function routes(): void {
		$namespace = Locations::rest_namespace();
		$origin    = '/origins/(?P<origin>[a-z0-9_-]+)';
		$routes    = array(
			$origin . '/feed'                => 'feed',
			$origin . '/items'               => 'index',
			$origin . '/items/(?P<id>[^/]+)' => 'item',
			$origin . '/items/(?P<id>[^/]+)/v/(?P<n>[^/]+)' => 'pinned',
		);
		foreach ( $routes as $route => $document ) {
			register_rest_route(
				$namespace,
				$route,
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => function ( WP_REST_Request $request ) use ( $document ) {
						return $this->respond( $request, $document );
					},
					// These are the public documents of a publication: anyone may read them.
					'permission_callback' => '__return_true',
					'show_in_index'       => false,
				)
			);
		}
	}

	/**
	 * Whether a route is one of the public document routes.
	 *
	 * @param string $route A REST route.
	 */
	private static function is_ours( string $route ): bool {
		return 0 === strpos( $route, '/' . Locations::rest_namespace() . '/origins/' );
	}

	/**
	 * The templated origin a request names, or null.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return Origin|null
	 */
	private function origin( WP_REST_Request $request ) {
		$key = (string) $request['origin'];
		if ( ! $this->registry->has( $key ) ) {
			return null;
		}
		$origin = $this->registry->get( $key );
		// A fixed origin serves its documents at the default paths, and only there.
		return 'templated' === $origin->layout ? $origin : null;
	}

	/**
	 * Builds the response for one document route.
	 *
	 * @param WP_REST_Request $request  The request.
	 * @param string          $document feed, index, item or pinned.
	 */
	private function respond( WP_REST_Request $request, string $document ): WP_REST_Response {
		$origin   = $this->origin( $request );
		$response = $origin ? $this->build( $origin, $request, $document ) : null;
		return self::wrap( null === $response ? Http::not_found() : $response );
	}

	/**
	 * One document's response, or null.
	 *
	 * @param Origin          $origin   The origin.
	 * @param WP_REST_Request $request  The request.
	 * @param string          $document feed, index, item or pinned.
	 * @return array|null
	 */
	private function build( Origin $origin, WP_REST_Request $request, string $document ) {
		$response = null;
		if ( $origin ) {
			switch ( $document ) {
				case 'feed':
					$response = Router::served( Http::RSS, $this->documents->feed( $origin ) );
					break;
				case 'index':
					$response = Router::served( Http::JSON, $this->documents->index( $origin ) );
					break;
				case 'item':
					$response = $this->router->item( $origin, (string) $request['id'] );
					break;
				case 'pinned':
					$response = $this->router->pinned( $origin, (string) $request['id'], (string) $request['n'] );
					break;
			}
		}
		return $response;
	}

	/**
	 * Wraps a surface response for the trip through the REST server.
	 *
	 * @param array $response A response for Http::send().
	 */
	private static function wrap( array $response ): WP_REST_Response {
		return new WP_REST_Response( array( self::MARKER => $response ), (int) $response['status'] );
	}

	/**
	 * Answers a CORS preflight on a document route.
	 *
	 * @param mixed           $result  A response to use instead of dispatching.
	 * @param WP_REST_Server  $server  The server.
	 * @param WP_REST_Request $request The request.
	 * @return mixed
	 */
	public function preflight( $result, $server, $request ) {
		if ( null === $result && 'OPTIONS' === $request->get_method() && self::is_ours( $request->get_route() ) ) {
			return self::wrap( Http::preflight() );
		}
		return $result;
	}

	/**
	 * Writes a document route's response ourselves.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_REST_Response $result  The response.
	 * @param WP_REST_Request  $request The request.
	 * @return bool
	 */
	public function serve( $served, $result, $request ) {
		if ( $served || ! self::is_ours( $request->get_route() ) ) {
			return $served;
		}
		$data = $result instanceof WP_REST_Response ? $result->get_data() : null;
		if ( is_array( $data ) && isset( $data[ self::MARKER ] ) ) {
			Http::send( $data[ self::MARKER ] );
			return true;
		}
		// Anything else on these routes is the REST server's own refusal: a method it does not route
		// here, or a route with no match. The surface answers those as it does everywhere else.
		$status = $result instanceof WP_REST_Response ? $result->get_status() : 404;
		if ( 401 === $status || 403 === $status ) {
			// Another plugin has put the REST API behind a login. Say so plainly rather than as a 404:
			// Site Health's reachability test reads this, and so will whoever is debugging it.
			Http::send(
				array(
					'status' => $status,
					'type'   => Http::TEXT,
					'body'   => "The REST API on this site requires authentication, so this blyg's documents cannot be read.\n",
					'cache'  => false,
				)
			);
			return true;
		}
		Http::send( 405 === $status ? Http::method_not_allowed( 'GET, HEAD, OPTIONS' ) : Http::not_found() );
		return true;
	}
}
