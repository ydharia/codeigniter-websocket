<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Namespaces
use React\EventLoop\LoopInterface;
use React\EventLoop\Factory as LoopFactory;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

/**
 * @package   CodeIgniter Ratchet WebSocket Library: Main class
 * @category  Libraries
 * @author    Taki Elias <taki.elias@gmail.com>
 * @license   http://opensource.org/licenses/MIT > MIT License
 * @link      https://github.com/takielias
 *
 * CodeIgniter WebSocket library. It allows you to make powerfull realtime applications by using Ratchet Websocket technology
 */

/**
 * Inspired By
 * Ratchet Websocket Library: helper file
 * @author Romain GALLIEN <romaingallien.rg@gmail.com>
 */

class Codeigniter_websocket
{
	/**
	 * CI Super Instance
	 * @var array
	 */
	private $CI;

	/**
	 * Default host var
	 * @var string
	 */
	public $host = null;

	/**
	 * Default host var
	 * @var string
	 */
	public $port = null;

	/**
	 * Default auth var
	 * @var bool
	 */
	public $auth = false;

	/**
	 * Default Timer Interval var
	 * @var bool
	 */
	public $timer_interval = 1;

	/**
	 * Default debug var
	 * @var bool
	 */
	public $debug = false;

	/**
	 * Auth callback informations
	 * @var array
	 */
	public $callback = array();

	/**
	 * Config vars
	 * @var array
	 */
	protected $config = array();

	/**
	 * Wss SSL vars
	 * @var array
	 */
	protected $ssl = array();
	public $server;
	public $server_class_obj;
	public $wssobj;

	/**
	 * Define allowed callbacks
	 * @var array
	 */
	protected $callback_type = array('auth', 'event', 'close', 'citimer', 'roomjoin', 'roomleave', 'roomchat');

	/**
	 * Class Constructor
	 * @method __construct
	 * @param array $config Configuration
	 * @return void
	 */
	public function __construct(array $config = array())
	{
		// Load the CI instance
		$this->CI = &get_instance();

		// Load the class helper
		$this->CI->load->helper('codeigniter_websocket');
		$this->CI->load->helper('jwt');
		$this->CI->load->helper('authorization');

		// Define the config vars
		$this->config = (!empty($config)) ? $config : array();

		// Config file verification
		if (empty($this->config)) {
			output('fatal', 'The configuration file does not exist');
		}

		// Assign HOST value to class var
		$this->host = (!empty($this->config['codeigniter_websocket']['host'])) ? $this->config['codeigniter_websocket']['host'] : '';

		// Assign PORT value to class var
		$this->port = (!empty($this->config['codeigniter_websocket']['port'])) ? $this->config['codeigniter_websocket']['port'] : '';

		// Assign AUTH value to class var
		$this->auth = (!empty($this->config['codeigniter_websocket']['auth'] && $this->config['codeigniter_websocket']['auth'])) ? true : false;

		// Assign DEBUG value to class var
		$this->debug = (!empty($this->config['codeigniter_websocket']['debug'] && $this->config['codeigniter_websocket']['debug'])) ? true : false;

		// Assign Timer value to class var
		$this->timer = (!empty($this->config['codeigniter_websocket']['timer_enabled'] && $this->config['codeigniter_websocket']['timer_enabled'])) ? true : false;

		// Assign Timer Interval value to class var
		$this->timer_interval = (!empty($this->config['codeigniter_websocket']['timer_interval'])) ? $this->config['codeigniter_websocket']['timer_interval'] : 1;
		$this->ssl = (!empty($this->config['codeigniter_websocket']['ssl'])) ? $this->config['codeigniter_websocket']['ssl'] : array();
	}

	/**
	 * Launch the server
	 * @method run
	 * @return string
	 */
	public function run()
	{
		// Initiliaze all the necessary class
		$this->server_class_obj = new Server();
		$this->wssobj = new WsServer(
			$this->server_class_obj
		);

		$loop = LoopFactory::create();

		$this->server = IoServer::factory(
			new HttpServer(
				$this->wssobj
			),
			$this->port,
			$this->host,
			$this->ssl
		);

		//If you want to use timer
		$this->wssobj->enableKeepAlive($this->server->loop, 5);
		if ($this->timer != false) {
			$this->server->loop->addPeriodicTimer($this->timer_interval, function () {
				if (!empty($this->callback['citimer'])) {
					call_user_func_array($this->callback['citimer'], array(date('d-m-Y h:i:s a', time())));
				}
			});

		}

		// Run the socket connection !
		$this->server->run();
	}

	/**
	 * Define a callback to use auth or event callback
	 * @method set_callback
	 * @param array $callback
	 * @return void
	 */
	public function set_callback($type = null, array $callback = array())
	{
		// Check if we have an authorized callback given
		if (!empty($type) && in_array($type, $this->callback_type)) {

			// Verify if the method does really exists
			if (is_callable($callback)) {

				// Register callback as class var
				$this->callback[$type] = $callback;
			} else {
				output('fatal', 'Method ' . $callback[1] . ' is not defined');
			}
		}
	}
}

/**
 * @package   CodeIgniter WebSocket Library: Server class
 * @category  Libraries
 * @author    Taki Elias <taki.elias@gmail.com>
 * @license   http://opensource.org/licenses/MIT > MIT License
 * @link      https://github.com/takielias
 *
 * CodeIgniter WebSocket library. It allows you to make powerfull realtime applications by using Ratchet Websocket technology
 */
class Server implements MessageComponentInterface
{
	/**
	 * List of connected clients
	 * @var array
	 */
	public $clients;

	/**
	 * List of subscribers (associative array)
	 * @var array
	 */
	public $subscribers = array();

	/**
	 * Class constructor
	 * @method __construct
	 */
	public function __construct()
	{
		// Load the CI instance
		$this->CI = &get_instance();

		// Initialize object as SplObjectStorage (see PHP doc)
		$this->clients = new SplObjectStorage;

		// // Check if auth is required
		if ($this->CI->codeigniter_websocket->auth && empty($this->CI->codeigniter_websocket->callback['auth'])) {
			output('fatal', 'Authentication callback is required, you must set it before run server, aborting..');
		}

		// Output
		if ($this->CI->codeigniter_websocket->debug) {
			output('success',
				'Running server on host ' . $this->CI->codeigniter_websocket->host . ':' . $this->CI->codeigniter_websocket->port);
		}

		// Output
		if (!empty($this->CI->codeigniter_websocket->callback['auth']) && $this->CI->codeigniter_websocket->debug) {
			output('success', 'Authentication activated');
		}

		// Output
		if (!empty($this->CI->codeigniter_websocket->callback['close']) && $this->CI->codeigniter_websocket->debug) {
			output('success', 'Close activated');
		}

	}

	/**
	 * Event trigerred on new client event connection
	 * @method onOpen
	 * @param ConnectionInterface $connection
	 * @return string
	 */
	public function onOpen(ConnectionInterface $connection)
	{
		// Add client to global clients object
		$this->clients->attach($connection);

		// Output
		if ($this->CI->codeigniter_websocket->debug) {
			output('info', 'New client connected as (' . $connection->resourceId . ')');
		}
	}

	/**
	 * Event trigerred on new message sent from client
	 * @method onMessage
	 * @param ConnectionInterface $client
	 * @param string $message
	 * @return string
	 */
	public function onMessage(ConnectionInterface $client, $message)
	{
		// Broadcast var
		$broadcast = false;

		// Check if received var is json format
		if (valid_json($message)) {
			// If true, we have to decode it
			$datas = json_decode($message);

			// Once we decoded it, we check look for global broadcast
			$broadcast = (!empty($datas->broadcast) and $datas->broadcast == true) ? true : false;

			// Count real clients numbers (-1 for server)
			$clients = count($this->clients) - 1;

			// Here we have to reassign the client ressource ID, this will allow us to send message to specified client.

			if (!empty($datas->type) && $datas->type == 'socket') {

				if (!empty($this->CI->codeigniter_websocket->callback['auth'])) {

					// Call user personnal callback
					$datas->resourceId = $client->resourceId;
					$auth = call_user_func_array($this->CI->codeigniter_websocket->callback['auth'], array($datas));
					$event = call_user_func_array($this->CI->codeigniter_websocket->callback['event'], array($datas));

					// Verify authentication
					$auth_data = is_object($auth) ? (array) $auth : $auth;
					$auth_error = 1;
					$auth_message = 'Invalid ID or Password.';
					$auth_user_id = null;

					if (is_array($auth_data)) {
						$auth_error = isset($auth_data['error']) ? (int) $auth_data['error'] : $auth_error;
						$auth_message = isset($auth_data['message']) ? $auth_data['message'] : $auth_message;
						$auth_user_id = isset($auth_data['user_id']) ? (int) $auth_data['user_id'] : $auth_user_id;
					} elseif (is_numeric($auth_data)) {
						// Backward compatibility: numeric return treated as user id
						$auth_error = 0;
						$auth_user_id = (int) $auth_data;
					}

					if ($auth_error !== 0 || empty($auth_user_id)) {
						output('error', 'Client (' . $client->resourceId . ') authentication failure');
						$data = json_encode(array("message" => json_encode(array("message_type" => "error", "message" => $auth_message))));
						$client->send($data);
						// Closing client connexion with error code "CLOSE_ABNORMAL"
						$client->close(1006);
						return;
					}

					// Add UID to associative array of subscribers
					$client->subscriber_id = $auth_user_id;
					$client->user_type = isset($datas->user_type) ? $datas->user_type : "";
					$client->system_id = isset($datas->system_id) ? $datas->system_id : "";
					$client->current_url = isset($datas->current_url) ? $datas->current_url : "";
					$client->current_method = isset($datas->current_method) ? $datas->current_method : "";
					$client->visit_time = date("Y-m-d H:i:s");
					$client->visit_timestamp = time();

					if ($this->CI->codeigniter_websocket->auth) {
						$token = AUTHORIZATION::generateToken($client->resourceId);
						$data = json_encode(array("message" => json_encode(array("message_type" => "token", "token" => $token))));
						output('success', 'Client (' . $client->resourceId . ') authentication success');
						$this->send_message($client, $data, $client);
					}

					// Output
					if ($this->CI->codeigniter_websocket->debug) {
						output('success', 'Client (' . $client->resourceId . ') authentication success');
					}
				}

			}


			if (!empty($datas->type) && $datas->type == 'roomjoin') {

				$token_valid = false;
				if (isset($client->user_type) && $client->user_type == 'exe_user') {
					$token_valid = true;
				} elseif (valid_jwt($datas->token) != false) {
					$token_valid = true;
				}

				if ($token_valid) {

					if (!empty($this->CI->codeigniter_websocket->callback['roomjoin'])) {

						// Call user personnal callback
						call_user_func_array($this->CI->codeigniter_websocket->callback['roomjoin'],
							array($datas, $client));

					}


				} else {

					$data = json_encode(array("message" => json_encode(array("message_type" => "error", "message" => "Invalid Token."))));
					$client->send($data);
				}

			}

			if (!empty($datas->type) && $datas->type == 'roomleave') {

				$token_valid = false;
				if (isset($client->user_type) && $client->user_type == 'exe_user') {
					$token_valid = true;
				} elseif (valid_jwt($datas->token) != false) {
					$token_valid = true;
				}

				if ($token_valid) {

					if (!empty($this->CI->codeigniter_websocket->callback['roomleave'])) {

						// Call user personnal callback
						call_user_func_array($this->CI->codeigniter_websocket->callback['roomleave'],
							array($datas, $client));

					}


				} else {

					$data = json_encode(array("message" => json_encode(array("message_type" => "error", "message" => "Invalid Token."))));
					$client->send($data);
				}

			}

			if (!empty($datas->type) && $datas->type == 'roomchat') {

				$token_valid = false;
				if (isset($client->user_type) && $client->user_type == 'exe_user') {
					$token_valid = true;
				} elseif (valid_jwt($datas->token) != false) {
					$token_valid = true;
				}

				if ($token_valid) {

					if (!empty($this->CI->codeigniter_websocket->callback['roomchat'])) {

						// Call user personnal callback
						call_user_func_array($this->CI->codeigniter_websocket->callback['roomchat'],
							array($datas, $client));

					}


				} else {

					$data = json_encode(array("message" => json_encode(array("message_type" => "error", "message" => "Invalid Token."))));
					$client->send($data);
				}

			}


			// Now this is the management of messages destinations, at this moment, 4 possibilities :
			// 1 - Message is not an array OR message has no destination (broadcast to everybody except us)
			// 2 - Message is an array and have destination (broadcast to single user)
			// 3 - Message is an array and don't have specified destination (broadcast to everybody except us)
			// 4 - Message is an array and we wan't to broadcast to ourselves too (broadcast to everybody)

			if (!empty($datas->type) && $datas->type == 'chat') {

				$pass = true;

				if ($this->CI->codeigniter_websocket->auth) {

					if (!valid_jwt($datas->token)) {
						output('error', 'Client (' . $client->resourceId . ') authentication failure. Invalid Token');
						$data = json_encode(array("message" => json_encode(array("message_type" => "error", "message" => "Invalid Token."))));
						$client->send($data);
						// Closing client connexion with error code "CLOSE_ABNORMAL"
						$client->close(1006);
						$pass = false;
					}
				}

				if ($pass) {
					if (!empty($message)) {
						$sanitized_message = $message;
						if (isset($datas->token)) {
							$decoded_message = json_decode($message, true);
							if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_message)) {
								unset($decoded_message['token']);
								$sanitized_message = json_encode($decoded_message);
							}
						}

						// We look arround all clients
						foreach ($this->clients as $user) {

							// Broadcast to single user
							if (!empty($datas->recipient_id)) {
								if (isset($user->subscriber_id) && $user->subscriber_id == $datas->recipient_id) {
									$this->send_message($user, $client === $user ? $message : $sanitized_message, $client);
									break;
								}
							} else {
								// Broadcast to everybody
								if ($broadcast) {
									$this->send_message($user, $client === $user ? $message : $sanitized_message, $client);
								} else {
									// Broadcast to everybody except us
									if ($client !== $user) {
										$this->send_message($user, $sanitized_message, $client);
									}
								}
							}
						}
					}
				}

			}

			if (!empty($datas->type) && $datas->type == 'get_connected_list') {

				$pass = true;

				if ($this->CI->codeigniter_websocket->auth) {

					if (isset($client->user_type) && $client->user_type == 'exe_user') {
						$pass = true;
					} elseif (!valid_jwt($datas->token)) {
						output('error', 'Client (' . $client->resourceId . ') authentication failure. Invalid Token');
						$data = json_encode(array("message" => json_encode(array("message_type" => "error", "message" => "Invalid Token."))));
						$client->send($data);
						// Closing client connexion with error code "CLOSE_ABNORMAL"
						$client->close(1006);
						$pass = false;
					}
				}

				if ($pass) {
					if (!empty($message)) {
						$list = array();
						foreach ($this->clients as $user) {
							if ($datas->message != "" && $datas->message != NULL) {
								if (isset($user->system_id) && trim($datas->message) == trim($user->system_id)) {
									if (isset($user->subscriber_id)) {
										$user_info = array();
										if (isset($user->WebSocket)) {
											$user_info["WebSocket"] = $user->WebSocket;
										} else {
											$user_info["WebSocket"] = "";
										}
										if (isset($user->remoteAddress)) {
											$user_info["remoteAddress"] = $user->remoteAddress;
										} else {
											$user_info["remoteAddress"] = "";
										}
										if (isset($user->subscriber_id)) {
											$user_info["WebSocket"] = $user->subscriber_id;
										} else {
											$user_info["WebSocket"] = "";
										}
										if (isset($user->resourceId)) {
											$user_info["resourceId"] = $user->resourceId;
										} else {
											$user_info["resourceId"] = "";
										}
										if (isset($user->system_id)) {
											$user_info["system_id"] = $user->system_id;
										} else {
											$user_info["system_id"] = "";
										}
										if (isset($user->user_type)) {
											$user_info["user_type"] = $user->user_type;
										} else {
											$user_info["user_type"] = "";
										}
										if (isset($user->current_url)) {
											$user_info["current_url"] = $user->current_url;
										} else {
											$user_info["current_url"] = "";
										}
										if (isset($user->current_method)) {
											$user_info["current_method"] = $user->current_method;
										} else {
											$user_info["current_method"] = "";
										}
										if (isset($user->visit_time)) {
											$user_info["visit_time"] = $user->visit_time;
										} else {
											$user_info["visit_time"] = "";
										}
										if (isset($user->visit_timestamp)) {
											$user_info["visit_timestamp"] = $user->visit_timestamp;
										} else {
											$user_info["visit_timestamp"] = "";
										}
										$list[$user->subscriber_id] = $user_info;
									}
									// $list[$user->subscriber_id] = array("WebSocket" => $user->WebSocket, "remoteAddress" => $user->remoteAddress, "subscriber_id" => $user->subscriber_id, "resourceId" => $user->resourceId, "system_id" => $user->system_id, "user_type" => $user->user_type, "current_url" => $user->current_url, "current_method" => $user->current_method, "visit_time" => $user->visit_time, "visit_timestamp" => $user->visit_timestamp);
									// output('info', "Single Info");
									// output('info', $datas->message);
									// output('info', json_encode($list));
									break;
								}
							} else {
								if (isset($user->subscriber_id)) {
									$user_info = array();
									if (isset($user->WebSocket)) {
										$user_info["WebSocket"] = $user->WebSocket;
									} else {
										$user_info["WebSocket"] = "";
									}
									if (isset($user->remoteAddress)) {
										$user_info["remoteAddress"] = $user->remoteAddress;
									} else {
										$user_info["remoteAddress"] = "";
									}
									if (isset($user->subscriber_id)) {
										$user_info["WebSocket"] = $user->subscriber_id;
									} else {
										$user_info["WebSocket"] = "";
									}
									if (isset($user->resourceId)) {
										$user_info["resourceId"] = $user->resourceId;
									} else {
										$user_info["resourceId"] = "";
									}
									if (isset($user->system_id)) {
										$user_info["system_id"] = $user->system_id;
									} else {
										$user_info["system_id"] = "";
									}
									if (isset($user->user_type)) {
										$user_info["user_type"] = $user->user_type;
									} else {
										$user_info["user_type"] = "";
									}
									if (isset($user->current_url)) {
										$user_info["current_url"] = $user->current_url;
									} else {
										$user_info["current_url"] = "";
									}
									if (isset($user->current_method)) {
										$user_info["current_method"] = $user->current_method;
									} else {
										$user_info["current_method"] = "";
									}
									if (isset($user->visit_time)) {
										$user_info["visit_time"] = $user->visit_time;
									} else {
										$user_info["visit_time"] = "";
									}
									if (isset($user->visit_timestamp)) {
										$user_info["visit_timestamp"] = $user->visit_timestamp;
									} else {
										$user_info["visit_timestamp"] = "";
									}
									$list[$user->subscriber_id] = $user_info;
								}
							}
						}
						$sdata = array('message' => json_encode(array("clients" => $list, "message_type" => "online_users")));
						$client->send(json_encode($sdata));
					}
				}
			}

		} else {
			output('error', 'Client (' . $client->resourceId . ') Invalid json.');
			// Closing client connexion with error code "CLOSE_ABNORMAL"
			$client->close(1006);
		}

	}

	/**
	 * Event triggered when connection is closed (or user disconnected)
	 * @method onClose
	 * @param ConnectionInterface $connection
	 * @return string
	 */
	public function onClose(ConnectionInterface $connection)
	{
		// Output
		if ($this->CI->codeigniter_websocket->debug) {
			output('info', 'Client (' . $connection->resourceId . ') disconnected');
		}

		if (!empty($this->CI->codeigniter_websocket->callback['close'])) {
			call_user_func_array($this->CI->codeigniter_websocket->callback['close'], array($connection));
		}
		// Detach client from SplObjectStorage
		$this->clients->detach($connection);
	}

	/**
	 * Event trigerred when error occured
	 * @method onError
	 * @param ConnectionInterface $connection
	 * @param Exception $e
	 * @return string
	 */
	public function onError(ConnectionInterface $connection, \Exception $e)
	{
		// Output
		if ($this->CI->codeigniter_websocket->debug) {
			output('fatal', 'An error has occurred: ' . $e->getMessage());
		}

		// We close this connection
		$connection->close();
	}

	/**
	 * Function to send the message
	 * @method send_message
	 * @param array $user User to send
	 * @param array $message Message
	 * @param array $client Sender
	 * @return string
	 */
	protected function send_message($user = array(), $message = array(), $client = array())
	{
		// Send the message
		$user->send($message);
		output('info', $message);
		// We have to check if event callback must be called
		if (!empty($this->CI->codeigniter_websocket->callback['event'])) {

			// At this moment we have to check if we have authent callback defined
			call_user_func_array($this->CI->codeigniter_websocket->callback['event'],
				array((valid_json($message) ? json_decode($message) : $message)));

			// Output
			if ($this->CI->codeigniter_websocket->debug) {
				output('info', 'Callback event "' . $this->CI->codeigniter_websocket->callback['event'][1] . '" called');
			}
		}

		// Output
		if ($this->CI->codeigniter_websocket->debug) {
			output('info',
				'Client (' . $client->resourceId . ') send \'' . $message . '\' to (' . $user->resourceId . ')');
		}
	}

}
