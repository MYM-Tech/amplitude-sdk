<?php
namespace Zumba\Amplitude;

use Psr\Log;

class Amplitude
{
    use Log\LoggerAwareTrait;

    const AMPLITUDE_API_URL = 'https://api.eu.amplitude.com/2/httpapi';

    const EXCEPTION_MSG_NO_API_KEY = 'API Key is required to log an event';
    const EXCEPTION_MSG_NO_EVENT_TYPE = 'Event Type is required to log or queue an event';
    const EXCEPTION_MSG_NO_USER_OR_DEVICE = 'Either user_id or device_id required to log an event';

    /**
     * The API key to use for all events generated by this instance
     *
     * @var string
     */
    protected string $apiKey;

    /**
     * The API URL to use for all events generated by this instance
     * Default will be const AMPLITUDE_API_URL
     *
     * @var string
     */
    protected string $apiUrl;

    /**
     * The event that will be used for the next event being tracked
     *
     * @var Event
     */
    protected Event $event;

    /**
     * The user ID to use for events generated by this instance
     *
     * @var string|null
     */
    protected ?string $userId = null;

    /**
     * The user data to set on the next event logged to Amplitude
     *
     * @var array<string, string>
     */
    protected array $userProperties = [];

    /**
     * The device ID to use for events generated by this instance
     *
     * @var string|null
     */
    protected ?string $deviceId = null;

    /**
     * Queue of events, used to allow generating events that might happen prior to amplitude being fully initialized
     *
     * @var Event[]
     */
    protected array $queue = [];

    /**
     * Flag for if user is opted out of tracking
     *
     * @var boolean
     */
    protected bool $optOut = false;

    /**
     * Array of Amplitude instances
     *
     * @var Amplitude[]
     */
    private static array $instances = [];

    /**
     * Singleton to get named instance
     *
     * Using this is optional, it depends on the use-case if it is better to use a singleton instance or just create
     * a new object directly.
     *
     * Useful to possibly send multiple events for the same user in a single page load, or even keep track
     * of multiple named instances, each could track to its own api key and/or user/device ID.
     *
     * Each instance maintains its own:
     * - API Key
     * - User ID
     * - Device ID
     * - User Properties
     * - Event Queue (if events are queued before the amplitude instance is initialized)
     * - Event object - for the next event that will be sent or queued
     * - Logger
     * - Opt out status
     *
     * @param string $instanceName Optional, can use to maintain multiple singleton instances of amplitude, each with
     *   its own API key set
     *
     * @return self
     */
    public static function getInstance(string $instanceName = 'default'): Amplitude
    {
        if (empty(self::$instances[$instanceName])) {
            self::$instances[$instanceName] = new static();
        }
        return self::$instances[$instanceName];
    }

    /**
     * Constructor, optionally sets the api key
     *
     * @param string|null $apiKey
     * @param string|null $apiUrl
     */
    public function __construct(?string $apiKey = null, ?string $apiUrl = null)
    {
        $this->apiKey = $apiKey ?? '';
        $this->apiUrl = $apiUrl ?? self::AMPLITUDE_API_URL;
        // Initialize logger to be null logger
        $this->setLogger(new Log\NullLogger());
    }

    /**
     * Initialize amplitude
     *
     * This lets you set the api key, and optionally the user ID.
     *
     * @param string      $apiKey Amplitude API key
     * @param string|null $userId
     *
     * @return self
     */
    public function init(string $apiKey, string $userId = null): self
    {
        $this->apiKey = $apiKey;
        if (null !== $userId) {
            $this->setUserId($userId);
        }

        return $this;
    }

    /**
     * Log any events that were queued before amplitude was initialized
     *
     * Note that api key, and either the user ID or device ID need to be set prior to calling this.
     *
     * @return self
     *
     * @throws \LogicException
     */
    public function logQueuedEvents(): self
    {
        if (!$this->hasQueuedEvents()) {
            return $this;
        }

        if ('' === $this->apiKey) {
            throw new \DomainException('Event or api key not set, cannot send event');
        }

        $this->postData($this->queue);

        return $this->resetQueue();
    }

    /**
     * Clear out all events in the queue, without sending them to amplitude
     *
     * @return self
     */
    public function resetQueue(): self
    {
        $this->queue = [];

        return $this;
    }

    /**
     * Gets the event that will be used for the next event logged by call to logEvent() or queueEvent().
     *
     * You can also pass in an event or array of event properties.  If you pass in an event, it will be set as the
     * event to be used for the next call to queueEvent() or logEvent().
     *
     * @see logEvent()
     *
     * @param null|array|Event $event Can pass in an event to set as the next event to run, or array to set
     *   properties on that event.
     *
     * @return Event
     */
    public function event($event = null): Event
    {
        if (!empty($event) && $event instanceof Event) {
            $this->event = $event;
        } elseif (empty($this->event)) {
            // Set the values that persist between tracking events
            $this->event = new Event();
        }
        if (!empty($event) && is_array($event)) {
            // Set properties on the event
            $this->event->setProperties($event);
        }
        return $this->event;
    }

    /**
     * Resets the event currently in the process of being set up (what is returned by event()).
     *
     * @return self
     */
    public function resetEvent(): self
    {
        $this->event = new Event();

        return $this;
    }

    /**
     * Log an event immediately. Override previous data if arguments set.
     *
     * Requires amplitude is already initialized and user ID or device ID is set.  If need to wait until amplitude
     * is initialized, use queueEvent() method instead.
     *
     * Can either pass in information to be logged, or can set up the Event object beforehand, see the event()
     * method for more information.
     *
     * @param string $eventType Required if not set on event object prior to calling this.
     * @param array $eventProperties Optional, properties to set on event.
     *
     * @return self
     *
     * @throws \DomainException Throws exception if any if the requirements are not met, such as api key set
     */
    public function logEvent(string $eventType = '', array $eventProperties = []): self
    {
        if ($this->optOut) {
            return $this;
        }
        // Sanity checking
        if (empty($this->apiKey)) {
            throw new \DomainException(static::EXCEPTION_MSG_NO_API_KEY);
        }
        $event = $this->event();
        $event->setProperties($eventProperties);
        $event->eventType = $eventType ?: $event->eventType;
        // Set the persistent options on the event
        $this->setPersistentEventData();

        if (empty($event->eventType)) {
            throw new \DomainException(static::EXCEPTION_MSG_NO_EVENT_TYPE);
        }
        if (empty($event->userId) && empty($event->deviceId)) {
            throw new \DomainException(static::EXCEPTION_MSG_NO_USER_OR_DEVICE);
        }
        $this->event = $event;

        $this->postData([$this->event]);

        // Reset the event for next call
        $this->resetEvent();

        return $this;
    }

    /**
     * Set the persistent data on the event object
     *
     * @return void
     */
    protected function setPersistentEventData(): void
    {
        $event = $this->event();
        if (!empty($this->userId)) {
            $event->userId = $this->userId;
        }
        if (!empty($this->deviceId)) {
            $event->deviceId = $this->deviceId;
        }
        if (!empty($this->userProperties)) {
            $event->setUserProperties($this->userProperties);
            $this->resetUserProperties();
        }
    }

    /**
     * Log or queue the event, depending on if amplitude instance is already set up or not.
     *
     * Note that this is an internal queue, the queue is lost between page loads.
     *
     * Add event to queue to be logged later (during same page load).
     *
     * Queue will be processed after the amplitude instance is initialized and
     * logQueuedEvents() method is called.
     *
     * @param string $eventType
     * @param array $eventProperties
     *
     * @return self
     *
     * @throws \DomainException
     */
    public function queueEvent(string $eventType = '', array $eventProperties = []): self
    {
        if ($this->optOut) {
            return $this;
        }
        $event = $this->event();
        $event->setProperties($eventProperties);
        $event->eventType = $eventType ?: $event->eventType;

        // Sanity checking
        if ('' === $event->eventType) {
            throw new \DomainException(static::EXCEPTION_MSG_NO_EVENT_TYPE);
        }

        $this->queue[] = $event;
        $this->resetEvent();

        return $this;
    }

    /**
     * Set the user ID for future events to be logged.
     *
     * Any set with this will take precedence over any set on the Event object.
     *
     * @param string|null $userId
     * @return self
     */
    public function setUserId(?string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    /**
     * Set the device ID for future events logged
     *
     * Any set with this will take precedence over any set on the Event object
     *
     * @param string|null $deviceId
     *
     * @return self
     */
    public function setDeviceId(?string $deviceId): self
    {
        $this->deviceId = $deviceId;

        return $this;
    }

    /**
     * Set the user properties, will only be sent with the next event sent to Amplitude.
     *
     * Any set with this will take precedence over any set on the Event object.
     *
     * @param array $userProperties
     *
     * @return self
     */
    public function setUserProperties(array $userProperties): self
    {
        $this->userProperties = array_merge($this->userProperties, $userProperties);

        return $this;
    }

    /**
     * Resets user properties added with setUserProperties().
     *
     * @return self
     */
    public function resetUserProperties(): self
    {
        $this->userProperties = [];

        return $this;
    }

    /**
     * Check if there are events in the queue that have not been sent.
     *
     * @return boolean
     */
    public function hasQueuedEvents(): bool
    {
        return 0 < \count($this->queue);
    }

    public function countQueuedEvents(): int
    {
        return \count($this->queue);
    }

    /**
     * Resets all user information.
     *
     * This resets the user ID, device ID previously set using setUserId or setDeviceId.
     *
     * If additional information was previously set using setUserProperties() method, and the event has not already
     * been sent to Amplitude, it will reset that information as well.
     *
     * Does not reset user information if set manually on an individual event in the queue.
     *
     * @return self
     */
    public function resetUser(): self
    {
        $this->setUserId(null);
        $this->setDeviceId(null);
        $this->resetUserProperties();

        return $this;
    }

    /**
     * Set opt out for the current user.
     *
     * If set to true, will not send any future events to amplitude for this amplitude instance.
     *
     * @param boolean $optOut
     *
     * @return self
     */
    public function setOptOut(bool $optOut): self
    {
        $this->optOut = $optOut;

        return $this;
    }

    /**
     * Getter for currently set api key.
     *
     * @return string
     */
    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * Getter for currently set user ID.
     *
     * @return string|null
     */
    public function getUserId(): ?string
    {
        return $this->userId;
    }

    /**
     * Getter for currently set device ID
     *
     * @return string|null
     */
    public function getDeviceId(): ?string
    {
        return $this->deviceId;
    }

    /**
     * Getter for all currently set user properties, that will be automatically sent on next Amplitude event
     *
     * Once the properties have been sent in an Amplitude event, they will be cleared.
     *
     * @return array
     */
    public function getUserProperties(): array
    {
        return $this->userProperties;
    }

    /**
     * Get the current value for opt out.
     *
     * @return boolean
     */
    public function getOptOut(): bool
    {
        return $this->optOut;
    }

    /**
     * Send the event currently set in $this->event to amplitude
     *
     * Requires $this->event and $this->apiKey to be set, throws an exception otherwise.
     *
     * @return void
     * @throws \DomainException If event or api key not set
     */
    protected function sendEvent(): void
    {
        if ('' === $this->event->eventType || '' === $this->apiKey) {
            throw new \DomainException('Event or api key not set, cannot send event');
        }

        $this->postData([$this->event]);
    }

    /**
     * @param Event[] $events
     *
     * @return void
     */
    public function postData(array $events): void
    {
        $ch = curl_init($this->apiUrl);
        if (!$ch) {
            // Could be a number of PHP environment problems, log a critical error
            $this->logger->critical(
                'Call to curl_init(' . $this->apiUrl . ') failed, unable to send Amplitude event'
            );
            return;
        }
        $postFields = [
            'api_key' => $this->apiKey,
            'events' => $events,
        ];
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postFields));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        // Always return instead of outputting response!
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        if ($curlErrno) {
            $this->logger->critical(
                'Curl error: ' . curl_error($ch),
                compact('curlErrno', 'response', 'postFields')
            );
        } else {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $this->logger->log(
                $httpCode === 200 ? Log\LogLevel::INFO : Log\LogLevel::ERROR,
                'Amplitude HTTP API response: ' . $response,
                compact('httpCode', 'response', 'postFields')
            );
        }
        curl_close($ch);
    }
}
