<?php
// phpcs:ignoreFile WordPress.Security.EscapeOutput.OutputNotEscaped
class SLN_Admin_Reports extends SLN_Admin_AbstractPage
{

    const PAGE = 'salon-reports';
    const PRIORITY = 11;

    public function __construct(SLN_Plugin $plugin)
    {
        parent::__construct($plugin);
	add_action('in_admin_header', array($this, 'in_admin_header'));
    }

    public function admin_menu()
    {
        $this->classicAdminMenu(
            __('Salon Reports', 'salon-booking-system'),
            __('Reports', 'salon-booking-system')
        );
    }

    public function show()
    {
        // Use new modern dashboard
        echo $this->plugin->loadView(
            'admin/reports-dashboard',
            array(
                'plugin' => $this->plugin,
            )
        );
    }

    public function enqueueAssets()
    {
        // Enqueue Chart.js from CDN
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            array(),
            '4.4.0',
            true
        );

        // Enqueue new dashboard JavaScript
        wp_enqueue_script(
            'sln-reports-dashboard',
            SLN_PLUGIN_URL . '/js/admin/reports-dashboard.js',
            array('jquery', 'chart-js'),
            SLN_VERSION,
            true
        );
        
        // Enqueue debug script (optional - comment out for production)
        // if (defined('WP_DEBUG') && WP_DEBUG) {
        //     wp_enqueue_script(
        //         'sln-reports-dashboard-debug',
        //         SLN_PLUGIN_URL . '/js/admin/reports-dashboard-debug.js',
        //         array('jquery', 'sln-reports-dashboard'),
        //         SLN_VERSION,
        //         true
        //     );
        // }

        // Pass configuration to JavaScript
        wp_localize_script('sln-reports-dashboard', 'salonDashboard', array(
            'nonce'          => wp_create_nonce('wp_rest'),
            'apiToken'       => '', // Will be populated if using API token auth
            'currency'       => $this->plugin->getSettings()->getCurrency(),
            'currencySymbol' => SLN_Currency::getSymbolAsIs($this->plugin->getSettings()->getCurrency()),
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'restUrl'        => rest_url('salon/api/v1/'),
            'isDebug'        => defined('WP_DEBUG') && WP_DEBUG,
            'isPro'          => defined('SLN_VERSION_PAY') || defined('SLN_VERSION_CODECANYON'),
        ));

        parent::enqueueAssets();

        $event = 'Page views of back-end plugin pages';
        $data  = array(
            'page'   => 'reports',
            'report' => 'dashboard',
        );

        SLN_Action_InitScripts::mixpanelTrack($event, $data);
    }
}
