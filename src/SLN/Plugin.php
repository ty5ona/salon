<?php
// phpcs:ignoreFile WordPress.Security.EscapeOutput.OutputNotEscaped
class SLN_Plugin
{
    const POST_TYPE_SERVICE = 'sln_service';
    const POST_TYPE_ATTENDANT = 'sln_attendant';
    const POST_TYPE_SHOP = 'sln_shop';
    const POST_TYPE_BOOKING = 'sln_booking';
    const TAXONOMY_SERVICE_CATEGORY = 'sln_service_category';
    const POST_TYPE_RESOURCE = 'sln_resource';
    const USER_ROLE_STAFF = 'sln_staff';
    const USER_ROLE_CUSTOMER = 'sln_customer';
    const USER_ROLE_WORKER = 'sln_worker';
    const TEXT_DOMAIN = 'salon-booking-system';
    const DEBUG_ENABLED = false;
    const DEBUG_CACHE_ENABLED = false;
    const CATEGORY_ORDER = 'sln_service_category_order';

    private static $instance;
    /**
     * @var array<string,string|false>
     */
    private static $logFilePaths = array();
    private $settings;
    private $repositories;
    private $phpServices = array();

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function __construct()
    {
        $obj = new SLN_Action_Init($this);
    }


    /** @return SLN_Settings */
    public function getSettings()
    {
        if (!isset($this->settings)) {
            $this->settings = new SLN_Settings();
        }

        return $this->settings;
    }

    /**
     * @param $attendant
     * @return SLN_Wrapper_Attendant
     * @throws Exception
     */
    public function createAttendant($attendant)
    {
        if(is_array($attendant)){
            $ret = array();
            $repository = $this->getRepository(self::POST_TYPE_ATTENDANT);
            foreach($attendant as $attId){
                $ret[] = $repository->create($attId);
            }
            return $ret;
        }
        return $this->getRepository(self::POST_TYPE_ATTENDANT)->create($attendant);
    }

    /**
     * @param $service
     * @return SLN_Wrapper_Service
     * @throws Exception
     */
    public function createService($service)
    {
        return $this->getRepository(self::POST_TYPE_SERVICE)->create($service);
    }

    public function createBooking($booking)
    {
        if (is_string($booking) && strpos($booking, '-') !== false) {
            $booking = str_replace('?sln_step_page=summary', '',$booking);
            $secureId = $booking;
            $booking = intval($booking);
        }
        if (is_int($booking)) {
            $booking = get_post($booking);
        }
        $ret = new SLN_Wrapper_Booking($booking);
        if (isset($secureId) && $ret->getUniqueId() != $secureId) {
            throw new Exception('Not allowed, failing secure id');
        }

        return $ret;
    }

    /**
     * @return SLN_Wrapper_Booking_Builder
     */
    public function getBookingBuilder()
    {
        if(!isset($this->phpServices['bookingBuilder'])){
            $this->phpServices['bookingBuilder'] = new SLN_Wrapper_Booking_Builder($this);
        }
        return $this->phpServices['bookingBuilder'];
    }

    public function getViewFile($view)
    {
        return SLN_PLUGIN_DIR.'/views/'.$view.'.php';
    }

    public function loadView($view, $data = array())
    {
        return $this->templating()->loadView($view, $data);
    }

    public function sendMail($view, $data)
    {
	$data['data'] = $settings = new ArrayObject($data);

	$settings['attachments'] = array();
    $additional_fields = SLN_Enum_CheckoutFields::additional();
    foreach($additional_fields as $field){
        if($field['type'] === 'file' && isset($data['booking'])){
            $attachments = $data['booking']->getMeta($field['key']);
            if(is_array($attachments)){
                foreach($data['booking']->getMeta($field['key']) as $f){
                    if($f){
                        $settings['attachments'][] = implode('/', array_filter(array(wp_get_upload_dir()['basedir'], trim($f['subdir'], '/'), $f['file'])));
                    }
                }
            }else{
                if($attachments){
                    $settings['attachments'][] = implode('/', array_filter(array(wp_get_upload_dir()['basedir'], trim($f['subdir'], '/'), $attachments['file'])));
                }
            }
        }
    }

        $content = $this->loadView($view, $data);
        if (!function_exists('sln_html_content_type')) {

            function sln_html_content_type()
            {
                return 'text/html';
            }
        }

        add_filter('wp_mail_content_type', 'sln_html_content_type');
		$headers = array_merge(array(
			'From: '.$this->getSettings()->getSalonName().' <'.$this->getSettings()->getSalonEmail().'>',
			'booking-id: ' . (isset($data['booking']) ? $data['booking']->getId() : '0'),
			'remind: ' . ($data['remind'] ?? '0'),
		), isset($settings['headers']) ? $settings['headers'] : array());
        if(empty($settings['to'])){
            remove_filter('wp_mail_content_type', 'sln_html_content_type');
            return;
            //throw new Exception('Receiver not defined');
        }

        $admin_users = get_users(array(
            'role' => 'administrator'
        ));
        wp_mail($settings['to'], $settings['subject'], $content, $headers, $settings['attachments']);

        remove_filter('wp_mail_content_type', 'sln_html_content_type');
    }

    /**
     * @return SLN_Formatter
     */
    public function format()
    {
        if (!isset($this->phpServices['formatter'])) {
            $this->phpServices['formatter'] = new SLN_Formatter($this);
        }

        return $this->phpServices['formatter'];
    }

    /**
     * @return SLN_Service_Templating
     */
    public function templating()
    {
        if ( ! isset($this->phpServices['templating'])) {
            $obj = new SLN_Service_Templating($this);
            $obj->addPath(get_stylesheet_directory().'/salon-booking-templates/%s.php', 7);
            $obj->addPath(get_template_directory().'/salon-booking-templates/%s.php', 8);
            $obj->addPath(SLN_PLUGIN_DIR.'/views/%s.php', 10);
            $this->phpServices['templating'] = $obj;
        }

        return $this->phpServices['templating'];
    }

    /**
     * @return SLN_Helper_Availability
     */
    public function getAvailabilityHelper()
    {
        if (!isset($this->phpServices['availabilityHelper'])) {
            $this->phpServices['availabilityHelper'] = new SLN_Helper_Availability($this);
        }

        return $this->phpServices['availabilityHelper'];
    }

    /**
     * @return SLN_Wrapper_Booking_Cache
     */
    public function getBookingCache()
    {
        if (!isset($this->phpServices['bookingCache'])) {
            $this->phpServices['bookingCache'] = new SLN_Wrapper_Booking_Cache($this);
        }

        return $this->phpServices['bookingCache'];
    }

    /**
     * @param Datetime $datetime
     * @return \SLN_Helper_Intervals
     */
    public function getIntervals(DateTime $datetime, $duration = null)
    {
        $obj = new SLN_Helper_Intervals($this->getAvailabilityHelper());
        $obj->setDatetime($datetime, $duration);

        return $obj;
    }

    public function ajax()
    {
        SLN_TimeFunc::startRealTimezone();
        
        try {
            $method = sanitize_text_field(wp_unslash( $_REQUEST['method'] ));
            $className = 'SLN_Action_Ajax_'.ucwords($method);
            $classAltName = 'SLN_Action_Ajax_'.ucwords($method).'Alt';

            $isAlt = $this->getSettings()->isFormStepsAltOrder() && class_exists($classAltName);

            if ($isAlt || class_exists($className)) {
                if ($isAlt) {
                    $className = $classAltName;
                }
                SLN_Plugin::addLog('calling ajax '.$className);
                /** @var SLN_Action_Ajax_Abstract $obj */
                $obj = new $className($this);
                $ret = $obj->execute();
                SLN_Plugin::addLog("$className returned:\r\n".wp_json_encode($ret));
                
                if (is_array($ret)) {
                    header('Content-Type: application/json');
                    echo wp_json_encode($ret);
                } elseif (is_string($ret)) {
                    echo $ret;
                } else {
                    SLN_Plugin::addLog("ERROR: No valid content returned from $className");
                    throw new Exception("no content returned from $className");
                }
                exit();
            } else {
                SLN_Plugin::addLog("ERROR: ajax method not found: '$method'");
                throw new Exception("ajax method not found '$method'");
            }
        } catch (SLN_Action_Ajax_RedirectException $e) {
            // Handle redirect exceptions by returning proper JSON response
            SLN_Plugin::addLog("Redirect exception caught in ajax(): " . $e->getMessage());
            header('Content-Type: application/json');
            echo wp_json_encode(array(
                'redirect' => $e->getMessage()
            ));
            exit();
        } catch (Exception $e) {
            SLN_Plugin::addLog("EXCEPTION in ajax: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            
            // Send error notification to support
            if (class_exists('SLN_Helper_ErrorNotification')) {
                SLN_Helper_ErrorNotification::send(
                    'AJAX_EXCEPTION',
                    $e->getMessage(),
                    "Stack Trace:\n" . $e->getTraceAsString()
                );
            }
            
            // Return proper error JSON instead of letting WordPress return "0"
            header('Content-Type: application/json');
            $response = array(
                'error' => true,
                'message' => __('An error occurred during the booking process. Please try again or contact the website administrator.', 'salon-booking-system'),
            );
            
            // Include detailed error message if debug mode is enabled
            if (self::isDebugEnabled()) {
                $response['debug'] = $e->getMessage();
                $response['trace'] = $e->getTraceAsString();
            }
            
            echo wp_json_encode($response);
            exit();
        } catch (Throwable $e) {
            // Catch PHP 7+ fatal errors
            SLN_Plugin::addLog("FATAL ERROR in ajax: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            
            // Send critical error notification to support
            if (class_exists('SLN_Helper_ErrorNotification')) {
                SLN_Helper_ErrorNotification::send(
                    'FATAL_ERROR',
                    $e->getMessage(),
                    "Stack Trace:\n" . $e->getTraceAsString()
                );
            }
            
            header('Content-Type: application/json');
            echo wp_json_encode(array(
                'error' => true,
                'message' => __('A system error occurred. Please contact the website administrator.', 'salon-booking-system'),
            ));
            exit();
        }
    }

    public static function addLog($txt)
    {
        if (self::isDebugEnabled()) {
            $logPath = self::getLogFilePath('log.txt');
            if (!$logPath) {
                error_log('Salon Booking System: Unable to write to log.txt. Please review filesystem permissions.');
                return;
            }

            file_put_contents(
                $logPath,
                '['.date('Y-m-d H:i:s').'] '.$txt."\r\n",
                FILE_APPEND | LOCK_EX
            );
        }
    }

    public static function addLogCacheData($txt)
    {
        if (self::isDebugCacheEnabled()) {
            $logPath = self::getLogFilePath('log-cache.txt');
            if (!$logPath) {
                error_log('Salon Booking System: Unable to write to log-cache.txt. Please review filesystem permissions.');
                return;
            }

            file_put_contents(
                $logPath,
                '['.date('Y-m-d H:i:s').'] '.$txt."\r\n",
                FILE_APPEND | LOCK_EX
            );
        }
    }

    /**
     * Resolve the writable path for a log file.
     *
     * @param string $fileName
     * @return string|false
     */
    private static function getLogFilePath($fileName)
    {
        if (isset(self::$logFilePaths[$fileName])) {
            return self::$logFilePaths[$fileName];
        }

        $candidates = array(
            array(
                'dir'    => SLN_PLUGIN_DIR,
                'create' => false,
            ),
        );

        if (function_exists('wp_upload_dir')) {
            $uploadDir = wp_upload_dir(null, false);
            if (!empty($uploadDir['basedir'])) {
                $logsDir = rtrim($uploadDir['basedir'], '/\\').'/salon-booking-system/logs';
                $candidates[] = array(
                    'dir'    => $logsDir,
                    'create' => true,
                );
            }
        }

        foreach ($candidates as $candidate) {
            $dir = $candidate['dir'];

            if ($candidate['create'] && !is_dir($dir)) {
                if (function_exists('wp_mkdir_p')) {
                    if (!wp_mkdir_p($dir)) {
                        continue;
                    }
                } else {
                    if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                        continue;
                    }
                }
            }

            if (!is_dir($dir)) {
                continue;
            }

            if (!is_writable($dir)) {
                @chmod($dir, 0755);
                if (!is_writable($dir)) {
                    continue;
                }
            }

            $path = rtrim($dir, '/\\').'/'.$fileName;

            if (!file_exists($path)) {
                $handle = @fopen($path, 'a');
                if ($handle === false) {
                    continue;
                }
                fclose($handle);
            } elseif (!is_writable($path)) {
                @chmod($path, 0644);
                if (!is_writable($path)) {
                    continue;
                }
            }

            self::$logFilePaths[$fileName] = $path;

            return $path;
        }

        self::$logFilePaths[$fileName] = false;

        return false;
    }

    /**
     * Determine if standard debugging is enabled.
     *
     * @return bool
     */
    public static function isDebugEnabled()
    {
        $enabled = self::DEBUG_ENABLED;

        if (function_exists('get_option')) {
            $stored = get_option('sln_debug_enabled', null);
            if ($stored !== null && $stored !== false) {
                $enabled = self::normalizeBooleanFlag($stored);
            }
        }

        if (function_exists('getenv')) {
            $envFlag = getenv('SLN_DEBUG_ENABLED');
            if ($envFlag !== false && $envFlag !== null && $envFlag !== '') {
                $enabled = self::normalizeBooleanFlag($envFlag);
            }
        }

        if (defined('SLN_DEBUG_FORCE')) {
            $enabled = self::normalizeBooleanFlag(SLN_DEBUG_FORCE);
        }

        if (function_exists('apply_filters')) {
            $enabled = (bool) apply_filters('sln_debug_enabled', $enabled);
        }

        return $enabled;
    }

    /**
     * Determine if cache debugging is enabled.
     *
     * @return bool
     */
    public static function isDebugCacheEnabled()
    {
        $enabled = self::DEBUG_CACHE_ENABLED;

        if (function_exists('get_option')) {
            $stored = get_option('sln_debug_cache_enabled', null);
            if ($stored !== null && $stored !== false) {
                $enabled = self::normalizeBooleanFlag($stored);
            }
        }

        if (function_exists('getenv')) {
            $envFlag = getenv('SLN_DEBUG_CACHE_ENABLED');
            if ($envFlag !== false && $envFlag !== null && $envFlag !== '') {
                $enabled = self::normalizeBooleanFlag($envFlag);
            }
        }

        if (defined('SLN_DEBUG_CACHE_FORCE')) {
            $enabled = self::normalizeBooleanFlag(SLN_DEBUG_CACHE_FORCE);
        }

        if (function_exists('apply_filters')) {
            $enabled = (bool) apply_filters('sln_debug_cache_enabled', $enabled);
        }

        return $enabled;
    }

    /**
     * Normalize a truthy value coming from configuration sources.
     *
     * @param mixed $value
     * @return bool
     */
    private static function normalizeBooleanFlag($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return false;
            }

            return in_array($normalized, array('1', 'true', 'yes', 'on', 'enabled'), true);
        }

        return (bool) $value;
    }

    /**
     * @param $post
     *
     * @return SLN_Wrapper_Abstract
     * @throws Exception
     */
    public function createFromPost($post)
    {
        if (!is_object($post)) {
            $post = get_post($post);
            if (!$post) {
                throw new Exception('post not found');
            }
        }

        return $this->getRepository($post->post_type)->create($post);
    }

    public function addRepository(SLN_Repository_AbstractRepository $repo)
    {
        foreach ($repo->getBindings() as $k) {
            $this->repositories[$k] = $repo;
        }
    }

    /**
     * @param $binding
     * @return SLN_Repository_AbstractRepository
     * @throws \Exception
     */
    public function getRepository($binding)
    {
        $ret = $this->repositories[$binding];
        if (!$ret) {
            throw new Exception(sprintf('repository for "%s" not found', $binding));
        }

        return $ret;
    }

    /**
     * @return SLN_Service_Sms
     */
    public function sms()
    {
        if (!isset($this->phpServices['sms'])) {
            $this->phpServices['sms'] = new SLN_Service_Sms($this);
        }

        return $this->phpServices['sms'];
    }

    /**
     * @return SLN_Service_Messages
     */
    public function messages()
    {
        if (!isset($this->phpServices['messages'])) {
            $this->phpServices['messages'] = new SLN_Service_Messages($this);
        }

        return $this->phpServices['messages'];
    }

    /**
     * @param $resource
     * @return SLN_Wrapper_Resource
     * @throws Exception
     */
    public function createResource($resource)
    {
        return $this->getRepository(self::POST_TYPE_RESOURCE)->create($resource);
    }
}

function sln_sms_reminder()
{
    if (apply_filters('sln.scheduled.sms_reminder', false)) {
        return;
    }

    $obj = new SLN_Action_Reminder(SLN_Plugin::getInstance());
    $obj->executeSms();
}

function sln_email_reminder()
{
    if (apply_filters('sln.scheduled.email_reminder', false)) {
        return;
    }

    $obj = new SLN_Action_Reminder(SLN_Plugin::getInstance());
    $obj->executeEmail();
}

function sln_sms_followup()
{
    if (apply_filters('sln.scheduled.sms_followup', false)) {
        return;
    }

    $obj = new SLN_Action_FollowUp(SLN_Plugin::getInstance());
    $obj->executeSms();
}

function sln_email_followup()
{
    if (apply_filters('sln.scheduled.email_followup', false)) {
        return;
    }

    $obj = new SLN_Action_FollowUp(SLN_Plugin::getInstance());
    $obj->executeEmail();
}

function sln_email_feedback()
{
    if (apply_filters('sln.scheduled.email_feedback', false)) {
        return;
    }

    $obj = new SLN_Action_Feedback(SLN_Plugin::getInstance());
    $obj->execute();
}

function sln_cancel_bookings()
{
    $obj = new SLN_Action_CancelBookings(SLN_Plugin::getInstance());
    $obj->execute();
}

function sln_email_weekly_report()
{
    $obj = new SLN_Action_WeeklyReport(SLN_Plugin::getInstance());
    $obj->executeEmail();
}

function sln_clean_up_database()
{
    $obj = new SLN_Action_CleanUpDatabase(SLN_Plugin::getInstance());
    $obj->execute();
}
