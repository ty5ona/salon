<?php
// phpcs:ignoreFile WordPress.DB.PreparedSQL.NotPrepared

namespace SLB_API\Controller;

use WP_REST_Server;
use WP_Error;
use SLN_Plugin;
use WP_Query;
use WP_User;
use SLN_Enum_BookingStatus;
use SLN_Wrapper_Booking_Builder;
use SLN_Metabox_Helper;
use SLN_Wrapper_Booking;

class Bookings_Controller extends REST_Controller
{
    const POST_TYPE = SLN_Plugin::POST_TYPE_BOOKING;

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'bookings';
    protected $booking_id;
    protected $customer_id;
    protected $request;

    public function register_routes() {

        register_rest_route( $this->namespace, '/' . $this->rest_base, array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_items' ),
                'permission_callback' => array( $this, 'get_items_permissions_check' ),
                'args'		      => apply_filters('sln_api_bookings_register_routes_get_items_args', array(
                    'search' => array(
                        'description' => __( 'Search string.', 'salon-booking-system' ),
                        'type'        => 'string',
                        'default'     => '',
                    ),
                    'services' => array(
                        'description' => __( 'Services ids.', 'salon-booking-system' ),
                        'type'        => 'array',
                        'items'       => array(
                            'type' => 'integer',
                        ),
                    ),
                    'customers' => array(
                        'description' => __( 'Customers ids.', 'salon-booking-system' ),
                        'type'        => 'array',
                        'items'       => array(
                            'type' => 'integer',
                        ),
                    ),
                    'start_date' => array(
                        'description'       => __('Start date.', 'salon-booking-system'),
                        'type'              => 'string',
                        'format'            => 'YYYY-MM-DD',
                        'validate_callback' => array($this, 'rest_validate_request_arg'),
                    ),
                    'end_date' => array(
                        'description'       => __('End date.', 'salon-booking-system'),
                        'type'              => 'string',
                        'format'            => 'YYYY-MM-DD',
                        'validate_callback' => array($this, 'rest_validate_request_arg'),
                    ),
                    'order'      => array(
                        'description' => __('Order.', 'salon-booking-system'),
                        'type'        => 'string',
                        'enum'        => array('asc', 'desc'),
                        'default'     => 'asc',
                    ),
                    'orderby'      => array(
                        'description' => __('Order by.', 'salon-booking-system'),
                        'type'        => 'string',
                        'enum'        => array('id', 'date_time'),
                        'default'     => 'id',
                    ),
                    'per_page'      => array(
                        'description' => __('Per page.', 'salon-booking-system'),
                        'type'        => 'integer',
                        'default'     => 10,
                    ),
                    'page'      => array(
                        'description' => __('Page.', 'salon-booking-system'),
                        'type'        => 'integer',
                        'default'     => 1,
                    ),
                    'offset'      => array(
                        'description' => __('Offset.', 'salon-booking-system'),
                        'type'        => 'integer',
                    ),
                )),
            ),
            array(
                'methods'   => WP_REST_Server::CREATABLE,
                'callback'  => array( $this, 'create_item' ),
		'permission_callback' => '__return_true',
                'args'	    => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
            ),
            'schema' => array( $this, 'get_public_item_schema' ),
        ) );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/upcoming', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_upcoming_items' ),
                'permission_callback' => array( $this, 'get_items_permissions_check' ),
                'args' => array(
                    'hours' => array(
                        'description'       => __('Hours.', 'salon-booking-system'),
                        'type'              => 'integer',
                        'validate_callback' => array($this, 'rest_validate_request_arg'),
			'required'          => true,
                    ),
                ),
            ),
            'schema' => array( $this, 'get_public_item_schema' ),
        ) );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/stats', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_stats' ),
                'permission_callback' => array( $this, 'get_items_permissions_check' ),
                'args' => apply_filters('sln_api_bookings_register_routes_get_stats_args', array(
                    'start_date' => array(
                        'description'       => __('Start date.', 'salon-booking-system'),
                        'type'              => 'string',
                        'format'            => 'YYYY-MM-DD',
                        'required'          => true,
                        'validate_callback' => array($this, 'rest_validate_request_arg'),
                        'default'           => current_time('Y-01-01'),
                    ),
                    'end_date' => array(
                        'description'       => __('End date.', 'salon-booking-system'),
                        'type'              => 'string',
                        'format'            => 'YYYY-MM-DD',
                        'required'          => true,
                        'validate_callback' => array($this, 'rest_validate_request_arg'),
                        'default'           => current_time('Y-12-31'),
                    ),
                    'group_by' => array(
                        'description' => __( 'Group by.', 'salon-booking-system' ),
                        'type'        => 'string',
                        'enum'        => array('day', 'month', 'year'),
                        'required'    => true,
                        'default'     => 'month',
                    ),
                )),
            ),
        ) );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/stats/enhanced', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_enhanced_stats' ),
                'permission_callback' => array( $this, 'get_items_permissions_check' ),
                'args' => array(
                    'start_date' => array(
                        'description'       => __('Start date.', 'salon-booking-system'),
                        'type'              => 'string',
                        'format'            => 'YYYY-MM-DD',
                        'required'          => true,
                        'validate_callback' => array($this, 'rest_validate_request_arg'),
                    ),
                    'end_date' => array(
                        'description'       => __('End date.', 'salon-booking-system'),
                        'type'              => 'string',
                        'format'            => 'YYYY-MM-DD',
                        'required'          => true,
                        'validate_callback' => array($this, 'rest_validate_request_arg'),
                    ),
                    'group_by' => array(
                        'description' => __( 'Group by.', 'salon-booking-system' ),
                        'type'        => 'string',
                        'enum'        => array('day', 'week', 'month', 'quarter', 'year'),
                        'default'     => 'month',
                    ),
                    'compare_previous_period' => array(
                        'description' => __( 'Include previous period comparison.', 'salon-booking-system' ),
                        'type'        => 'boolean',
                        'default'     => false,
                    ),
                    'services' => array(
                        'description' => __( 'Filter by service IDs.', 'salon-booking-system' ),
                        'type'        => 'array',
                        'items'       => array('type' => 'integer'),
                    ),
                    'assistants' => array(
                        'description' => __( 'Filter by assistant IDs.', 'salon-booking-system' ),
                        'type'        => 'array',
                        'items'       => array('type' => 'integer'),
                    ),
                    'statuses' => array(
                        'description' => __( 'Filter by booking statuses.', 'salon-booking-system' ),
                        'type'        => 'array',
                        'items'       => array('type' => 'string'),
                    ),
                    'shop' => array(
                        'description' => __( 'Shop ID for multi-shop filtering (0 = all shops).', 'salon-booking-system' ),
                        'type'        => 'integer',
                        'default'     => 0,
                    ),
                ),
            ),
        ) );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/utilization', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_utilization' ),
                'permission_callback' => array( $this, 'get_items_permissions_check' ),
                'args' => array(
                    'start_date' => array(
                        'description'       => __('Start date.', 'salon-booking-system'),
                        'type'              => 'string',
                        'format'            => 'YYYY-MM-DD',
                        'required'          => true,
                        'validate_callback' => array($this, 'rest_validate_request_arg'),
                    ),
                    'end_date' => array(
                        'description'       => __('End date.', 'salon-booking-system'),
                        'type'              => 'string',
                        'format'            => 'YYYY-MM-DD',
                        'required'          => true,
                        'validate_callback' => array($this, 'rest_validate_request_arg'),
                    ),
                    'shop' => array(
                        'description' => __( 'Shop ID for multi-shop filtering (0 = all shops).', 'salon-booking-system' ),
                        'type'        => 'integer',
                        'default'     => 0,
                    ),
                ),
            ),
        ) );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/peak-times', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_peak_times' ),
                'permission_callback' => array( $this, 'get_items_permissions_check' ),
                'args' => array(
                    'start_date' => array(
                        'description'       => __('Start date.', 'salon-booking-system'),
                        'type'              => 'string',
                        'format'            => 'YYYY-MM-DD',
                        'required'          => true,
                        'validate_callback' => array($this, 'rest_validate_request_arg'),
                    ),
                    'end_date' => array(
                        'description'       => __('End date.', 'salon-booking-system'),
                        'type'              => 'string',
                        'format'            => 'YYYY-MM-DD',
                        'required'          => true,
                        'validate_callback' => array($this, 'rest_validate_request_arg'),
                    ),
                    'shop' => array(
                        'description' => __( 'Shop ID for multi-shop filtering (0 = all shops).', 'salon-booking-system' ),
                        'type'        => 'integer',
                        'default'     => 0,
                    ),
                ),
            ),
        ) );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/cancellations', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_cancellations' ),
                'permission_callback' => array( $this, 'get_items_permissions_check' ),
                'args' => array(
                    'start_date' => array(
                        'description'       => __('Start date.', 'salon-booking-system'),
                        'type'              => 'string',
                        'format'            => 'YYYY-MM-DD',
                        'required'          => true,
                        'validate_callback' => array($this, 'rest_validate_request_arg'),
                    ),
                    'end_date' => array(
                        'description'       => __('End date.', 'salon-booking-system'),
                        'type'              => 'string',
                        'format'            => 'YYYY-MM-DD',
                        'required'          => true,
                        'validate_callback' => array($this, 'rest_validate_request_arg'),
                    ),
                ),
            ),
        ) );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', array(
            'args' => array(
                'id' => array(
                    'description' => __( 'Unique identifier for the resource.', 'salon-booking-system' ),
                    'type'        => 'integer',
                    'required'    => true,
                ),
            ),
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_item' ),
                'permission_callback' => array( $this, 'get_item_permissions_check' ),
                'args'                => array(
                    'context' => $this->get_context_param( array( 'default' => 'view' ) ),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => array( $this, 'update_item' ),
                'permission_callback' => array( $this, 'update_item_permissions_check' ),
                'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'delete_item' ),
                'permission_callback' => array( $this, 'delete_item_permissions_check' ),
            ),
            'schema' => array( $this, 'get_public_item_schema' ),
        ) );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)/pay-remaining-amount', array(
            'args' => array(
                'id' => array(
                    'description' => __( 'Unique identifier for the resource.', 'salon-booking-system' ),
                    'type'        => 'integer',
                    'required'    => true,
                ),
            ),
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'pay_remaining_amount' ),
                'permission_callback' => array( $this, 'get_item_permissions_check' ),
            ),
        ) );
    }

    public function get_stats( $request )
    {
        global $wpdb;

        $formats = array(
            'day'   => '%e',
            'month' => '%c',
            'year'  => '%Y',
        );

        $periods = array(
            'day'   => array(
                'interval' => '1D',
                'format'   => 'j',
            ),
            'month'   => array(
                'interval' => '1M',
                'format'   => 'n',
            ),
            'year'   => array(
                'interval' => '1Y',
                'format'   => 'Y',
            ),
        );

        $p = $periods[$request->get_param('group_by')];

        $datePeriod = new \DatePeriod(
            new \DateTime($request->get_param('start_date')),
            new \DateInterval('P'.$p['interval']),
            (new \DateTime($request->get_param('end_date')))->modify('+1 day')
        );

        $stats = array();

        foreach ($datePeriod as $date) {
            $stats[$date->format($p['format'])] = array(
                'unit_type'      => $request->get_param('group_by'),
                'unit_value'     => $date->format($p['format']),
                'bookings_count' => 0,
            );
        }

        $format = $formats[$request->get_param('group_by')];

	$sql_joins = "INNER JOIN {$wpdb->prefix}postmeta pm ON p.id = pm.post_id
		    AND pm.meta_key = '_sln_booking_date'
		    AND DATE(pm.meta_value) >= '".(new \SLN_DateTime($request->get_param('start_date')))->format('Y-m-d')."'
		    AND DATE(pm.meta_value) <= '".(new \SLN_DateTime($request->get_param('end_date')))->format('Y-m-d')."'";


	$sql_joins = apply_filters('sln_api_bookings_get_stats_sql_joins', $sql_joins, $request);

        $results = $wpdb->get_results("
            SELECT
                COUNT(DISTINCT p.ID) as bookings_count,
                DATE_FORMAT(pm.meta_value, '".$format."') as unit_value
            FROM {$wpdb->prefix}posts p
            {$sql_joins}
            WHERE
                p.post_type = '".self::POST_TYPE."'
	    AND
		p.post_status <> 'trash'
            GROUP BY
                DATE_FORMAT(pm.meta_value, '".$format."')",
            OBJECT
        );

        foreach ($results as $result) {
            $stats[$result->unit_value] = array(
                'unit_type'      => $request->get_param('group_by'),
                'unit_value'     => $result->unit_value,
                'bookings_count' => (int)$result->bookings_count,
            );
        }

        return $this->success_response(array('items' => array_values($stats)));
    }

    public function get_items( $request )
    {
        if( !current_user_can( 'manage_salon' ) ){
            return rest_ensure_response( array(
                'status' => '403',
            ) );
        }
        $prepared_args          = array();
        $prepared_args['order'] = $request->get_param('order');
        $prepared_args['order'] = isset($prepared_args['order']) && in_array(strtolower($prepared_args['order']), array('asc', 'desc')) ? $prepared_args['order'] : 'asc';

        $prepared_args['posts_per_page'] = $request->get_param('per_page');

        $request['orderby'] = $request->get_param('orderby');
        $request['page']    = $request->get_param('page');

        if ( ! empty( $request['offset'] ) ) {
            $prepared_args['offset'] = $request['offset'];
        } else {
            $prepared_args['offset'] = ( $request['page'] - 1 ) * $prepared_args['posts_per_page'];
        }

        $orderby_possibles = array(
            'id'        => array('orderby' => 'ID'),
            'date_time' => array(
		'meta_query' => array(
		    'booking_date' => array(
			'key'	  => '_sln_booking_date',
			'type'    => 'DATE',
			'compare' => 'EXISTS',
		    ),
		    'booking_time' => array(
			'key'	  => '_sln_booking_time',
			'type'    => 'TIME',
			'compare' => 'EXISTS',
		    ),
		),
		'orderby' => 'booking_date booking_time',
            ),
        );

        $prepared_args = array_merge($prepared_args, $orderby_possibles[ $request['orderby'] ]);
        $prepared_args['post_type'] = self::POST_TYPE;

        if ($request->get_param('start_date')) {

            if ( ! isset( $prepared_args['meta_query'] ) ) {
                $prepared_args['meta_query'] = array();
            }

	    $_start_date = $request->get_param('start_date');

	    $_meta = array();

            $_meta[] = array(
                'key'     => '_sln_booking_date',
                'value'   => $_start_date,
                'compare' => '>=',
                'type'    => 'DATE',
            );

	    $prepared_args['meta_query'][] = $_meta;
        }

        if ($request->get_param('end_date')) {

            if ( ! isset( $prepared_args['meta_query'] ) ) {
                $prepared_args['meta_query'] = array();
            }

	    $_end_date = $request->get_param('end_date');

	    $_meta = array();

            $_meta[] = array(
                'key'     => '_sln_booking_date',
                'value'   => $_end_date,
                'compare' => '<=',
                'type'    => 'DATE',
            );

	    $prepared_args['meta_query'][] = $_meta;
        }

        if ($request->get_param('customers')) {
            $prepared_args['author__in'] = $request->get_param('customers');
        }

        if ($request->get_param('services')) {

            if ( ! isset( $prepared_args['meta_query'] ) ) {
                $prepared_args['meta_query'] = array();
            }

            $prepared_args['meta_query'][] = array(
                'key'     => '_sln_booking_services',
                'value'   => implode('|', array_map(function ($v) {
                    return sprintf('\"service\"\;\i\:%s\;', $v);
                }, $request->get_param('services'))),
                'compare' => 'REGEXP',
            );
        }

        $s = $request->get_param('search');

        if ($s !== '') {

            if ( ! isset( $prepared_args['meta_query'] ) ) {
                $prepared_args['meta_query'] = array();
            }

            $prepared_args['meta_query'][] = array(
                'relation' => 'OR',
                array(
                    'key'     => '_sln_booking_firstname',
                    'value'   => $s,
                    'compare' => 'LIKE',
                ),
                array(
                    'key'     => '_sln_booking_lastname',
                    'value'   => $s,
                    'compare' => 'LIKE',
                ),
                array(
                    'key'     => '_sln_booking_email',
                    'value'   => $s,
                    'compare' => 'LIKE',
                ),
                array(
                    'key'     => '_sln_booking_phone',
                    'value'   => $s,
                    'compare' => 'LIKE',
                ),
            );
        }

        $bookings = array();

	$prepared_args = apply_filters('sln_api_bookings_get_items_prepared_args', $prepared_args, $request);

        $query = new WP_Query( $prepared_args );

        try {
            foreach ( $query->posts as $booking ) {
                $data        = $this->prepare_item_for_response( $booking, $request );
                $bookings[]  = $this->prepare_response_for_collection( $data );
            }
        } catch (\Exception $ex) {
            return new WP_Error( 'salon_rest_cannot_view', __( 'Sorry, resource list error ('.sprintf('%s', $ex->getMessage()).').', 'salon-booking-system' ), array( 'status' => 404 ) );
        }

        $response = $this->success_response(array('items' => $bookings));

        // Store pagination values for headers then unset for count query.
        $per_page = (int) $prepared_args['posts_per_page'];
        $page     = ceil( ( ( (int) $prepared_args['offset'] ) / $per_page ) + 1 );

	$prepared_args['fields'] = 'ID';

        $total = $query->found_posts;

        if ( $total < 1 ) {
            // Out-of-bounds, run the query again without LIMIT for total count.
            unset( $prepared_args['posts_per_page'] );
            unset( $prepared_args['offset'] );
            $count_query = new WP_Query( $prepared_args );
            $total = $count_query->found_posts;
        }

        $response->header( 'X-WP-Total', (int) $total );

        $max_pages = ceil( $total / $per_page );

        $response->header( 'X-WP-TotalPages', (int) $max_pages );

        $base = add_query_arg( $request->get_query_params(), rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ) );

        if ( $page > 1 ) {
            $prev_page = $page - 1;
            if ( $prev_page > $max_pages ) {
                $prev_page = $max_pages;
            }
            $prev_link = add_query_arg( 'page', $prev_page, $base );
            $response->link_header( 'prev', $prev_link );
        }

        if ( $max_pages > $page ) {
            $next_page = $page + 1;
            $next_link = add_query_arg( 'page', $next_page, $base );
            $response->link_header( 'next', $next_link );
        }

        return $response;
    }

    public function get_upcoming_items( $request )
    {
	$current_datetime = \SLN_TimeFunc::currentDateTime();

	$from_date = $current_datetime->format('Y-m-d');
	$from_time = $current_datetime->format('H:i:s');

	$to_datetime = $current_datetime->add(new \DateInterval('PT'.((int)($request['hours'] * 3600)).'S'));

	$to_date = $to_datetime->format('Y-m-d');
	$to_time = $to_datetime->format('H:i:s');

        $prepared_args = array(
	    'orderby'	    => 'sln_booking_date sln_booking_time',
	    'order'	    => 'ASC',
	    'post_type'	    => self::POST_TYPE,
	    'post_status'   => array(
		SLN_Enum_BookingStatus::PAID,
		SLN_Enum_BookingStatus::PAY_LATER,
		SLN_Enum_BookingStatus::CONFIRMED,
	    ),
	);

	if ($from_date === $to_date) {

	    $prepared_args1 = array_merge($prepared_args, array(
		'meta_query' => array(
		    array(
			'sln_booking_date' => array(
			    'key'     => '_sln_booking_date',
			    'value'   => $from_date,
			    'compare' => '=',
			    'type'    => 'DATE',
			),
			'sln_booking_time' => array(
			    'key'     => '_sln_booking_time',
			    'value'   => $from_time,
			    'compare' => '>=',
			    'type'    => 'TIME',
			),
			array(
			    'key'     => '_sln_booking_time',
			    'value'   => $to_time,
			    'compare' => '<=',
			    'type'    => 'TIME',
			),
		    ),
		),
	    ));

	    $query1 = new WP_Query( $prepared_args1 );
	    $posts  = $query1->posts;

	} else {

	    $prepared_args1 = array_merge($prepared_args, array(
		'meta_query' => array(
		    array(
			'sln_booking_date' => array(
			    'key'     => '_sln_booking_date',
			    'value'   => $from_date,
			    'compare' => '=',
			    'type'    => 'DATE',
			),
			'sln_booking_time' => array(
			    'key'     => '_sln_booking_time',
			    'value'   => $from_time,
			    'compare' => '>=',
			    'type'    => 'TIME',
			),
		    ),
		),
	    ));

	    $prepared_args2 = array_merge($prepared_args, array(
		'meta_query' => array(
		    array(
			'sln_booking_date' => array(
			    'key'     => '_sln_booking_date',
			    'value'   => $from_date,
			    'compare' => '>',
			    'type'    => 'DATE',
			),
			array(
			    'key'     => '_sln_booking_date',
			    'value'   => $to_date,
			    'compare' => '<',
			    'type'    => 'DATE',
			),
			'sln_booking_time' => array(
			    'key'     => '_sln_booking_time',
			    'compare' => 'EXISTS',
			    'type'    => 'TIME',
			),
		    ),
		),
	    ));

	    $prepared_args3 = array_merge($prepared_args, array(
		'meta_query' => array(
		    array(
			'sln_booking_date' => array(
			    'key'     => '_sln_booking_date',
			    'value'   => $to_date,
			    'compare' => '=',
			    'type'    => 'DATE',
			),
			'sln_booking_time' => array(
			    'key'     => '_sln_booking_time',
			    'value'   => $to_time,
			    'compare' => '<=',
			    'type'    => 'TIME',
			),
		    ),
		),
	    ));

	    $query1 = new WP_Query( $prepared_args1 );
	    $query2 = new WP_Query( $prepared_args2 );
	    $query3 = new WP_Query( $prepared_args3 );

	    $posts = array_merge($query1->posts, $query2->posts, $query3->posts);
	}

        $bookings = array();

        try {
            foreach ( $posts as $booking ) {
                $data        = $this->prepare_item_for_response( $booking, $request );
                $bookings[]  = $this->prepare_response_for_collection( $data );
            }
        } catch (\Exception $ex) {
            return new WP_Error( 'salon_rest_cannot_view', __( 'Sorry, resource list error ('.sprintf('%s', $ex->getMessage()).').', 'salon-booking-system' ), array( 'status' => 404 ) );
        }

        $response = $this->success_response(array('items' => $bookings));

        return $response;
    }

    public function prepare_item_for_response( $booking, $request )
    {
        return SLN_Plugin::getInstance()->createBooking($booking);
    }

    public function prepare_response_for_collection($booking)
    {
        $tmp_services = $booking->getBookingServices();
        $tmp_services = $tmp_services ? $tmp_services->getItems() : array();
        $services     = array();

        foreach ($tmp_services as $service) {
            if($attendant = $service->getAttendant()){
                if(!is_array($attendant)){
                    $attName = $attendant->getName();
                    $attId = $attendant->getId();
                }else{
                    $attName = \SLN_Wrapper_Attendant::implodeArrayAttendantsName(', ', $attendant);
                    $attId = \SLN_Wrapper_Attendant::getArrayAttendantsValue('getId', $attendant);
                }
            }else{
                $attName = null;
                $attId = null;
            }
            $services[] = array(
                'start_at'       => $service->getStartsAt()->format('H:i'),
                'end_at'         => $service->getEndsAt()->format('H:i'),
                'service_id'     => $service->getService() ? $service->getService()->getId() : null,
                'service_name'   => $service->getService() ? $service->getService()->getName() : null,
                'service_price'  => $service->getService() ? $service->getService()->getPrice() : null,
                'assistant_id'   => $attId,
                'assistant_name' => $attName,
                'resource_id'    => $service->getResource() ? $service->getResource()->getId() : null,
                'resource_name'  => $service->getResource() ? $service->getResource()->getTitle() : null,
            );
        }

        $response = array(
            'id'                  => $booking->getId(),
            'created'             => $booking->getPostDate()->getTimestamp(),
            'date'                => $booking->getDate()->format('Y-m-d'),
            'time'                => $booking->getTime()->format('H:i'),
            'status'              => $booking->getStatus(),
            'customer_id'         => $booking->getCustomer() ? $booking->getCustomer()->getId() : $booking->getCustomer(),
            'customer_first_name' => $booking->getFirstname(),
            'customer_last_name'  => $booking->getLastname(),
            'customer_email'      => $booking->getEmail(),
            'customer_phone_country_code' => $booking->getSmsPrefix(),
            'customer_phone'      => $booking->getPhone(),
            'customer_address'    => $booking->getAddress(),
            'services'            => $services,
            'discounts'           => $booking->getMeta('discounts') ? $booking->getMeta('discounts') : array(),
            'duration'            => $booking->getDuration()->format('H:i'),
            'amount'              => $booking->getAmount(),
            'deposit'             => $booking->getDeposit(),
            'paid_remained'       => $booking->getPaidRemainedAmount(),
            'currency'            => SLN_Plugin::getInstance()->getSettings()->getCurrencySymbol(),
            'transaction_id'      => $booking->getTransactionId(),
            'note'                => $booking->getNote(),
            'admin_note'	  => $booking->getAdminNote(),
        );

	return apply_filters('sln_api_bookings_prepare_response_for_collection', $response, $booking);
    }

    public function create_item( $request )
    {
        if ($request->get_param('id')) {

            $query = new WP_Query(array(
                'post_type' => self::POST_TYPE,
                'p'         => $request->get_param('id'),
            ));

            if ( $query->posts ) {
                return new WP_Error( 'salon_rest_cannot_view', __( 'Sorry, resource already exists.', 'salon-booking-system' ), array( 'status' => 409 ) );
            }
        }

        try {

	    do_action('sln_api_bookings_create_item_before_create_post', $request);

	    $customer_id = $request->get_param('customer_id');

	    if ( ! $customer_id && $request->get_param('customer_email') ) {
		$customer_id = $this->create_new_customer($request);
	    }

            if ( $customer_id ) {
                $customer_data  = $this->get_customer_data_by_id($customer_id);
            }

            $cloned_request = clone $request;

            if ( ! empty( $customer_data ) ) {
                $cloned_request->set_default_params(array_merge($cloned_request->get_default_params(), $customer_data));
            }

            $data = $this->create_item_post($cloned_request, $customer_id);

        } catch (\Exception $ex) {
            return new WP_Error( 'salon_rest_cannot_create', $ex->getMessage(), array( 'status' => $ex->getCode() ? $ex->getCode() : 404 ) );
        }

        $response = $this->success_response(array(
	    'id'	  => $data['id'],
	    'customer_id' => $data['customer_id']
	));

        $response->set_status(201);

        return $response;
    }

    protected function get_customer_data_by_id($customer_id)
    {
        $user = new WP_User($customer_id);

        if( ! $user->ID ) {
	    throw new \Exception(esc_html__( "Customer doesn't exists.", 'salon-booking-system' ), 500);
        }

        return array(
            'customer_first_name' => $user->user_firstname,
            'customer_last_name'  => $user->user_lastname,
            'customer_email'      => $user->user_email,
            'customer_phone'      => get_user_meta($user->ID, '_sln_phone', true),
            'customer_address'    => get_user_meta($user->ID, '_sln_address', true),
        );
    }

    protected function create_new_customer($request)
    {
	$email = $request->get_param('customer_email');

	if ( ! $email ) {
	    throw new \Exception(esc_html__( 'Customer email empty.', 'salon-booking-system' ));
	}

	$user = get_user_by('email', $email);

	if ( $user ) {
	    return $user->ID;
	}

        $id = wp_create_user($email, wp_generate_password(), $email);

	if ( is_wp_error($id) ) {
	    throw new \Exception(esc_html__( 'Create new customer error.', 'salon-booking-system' ));
	}

        $id = wp_update_user(array(
            'ID'         => $id,
            'user_email' => $email,
            'first_name' => $request->get_param('customer_first_name'),
            'last_name'  => $request->get_param('customer_last_name'),
            'role'       => SLN_Plugin::USER_ROLE_CUSTOMER,
        ));

        if ( is_wp_error($id) ) {
            throw new \Exception(esc_html__( 'Save new customer error.', 'salon-booking-system' ));
        }

        $meta = array(
            '_sln_phone'    => $request->get_param('customer_phone'),
            '_sln_address'  => $request->get_param('customer_address'),
        );

        foreach ($meta as $key => $value) {
            update_user_meta($id, $key, $value);
        }

        return $id;
    }

    public function get_item( $request )
    {
        $query = $this->get_item_query($request->get_param('id'), $request);

        if ( ! $query->posts ) {
            return new WP_Error( 'salon_rest_cannot_view', __( 'Sorry, resource not found.', 'salon-booking-system' ), array( 'status' => 404 ) );
        }

        try {
            $booking = $this->prepare_item_for_response(current($query->posts), $request);
            $booking = $this->prepare_response_for_collection($booking);
        } catch (\Exception $ex) {
            return new WP_Error( 'salon_rest_cannot_view', __( 'Sorry, get resource error ('.sprintf('%s', $ex->getMessage()).').', 'salon-booking-system' ), array( 'status' => 404 ) );
        }

        return $this->success_response(array('items' => array($booking)));
    }

    public function update_item( $request )
    {
	$booking_id = $request->get_url_params()['id'];

	$query = $this->get_item_query($booking_id, $request);

        if ( ! $query->posts ) {
            return new WP_Error( 'salon_rest_cannot_view', __( 'Sorry, resource not found.', 'salon-booking-system' ), array( 'status' => 404 ) );
        }

        try {
            $booking = $this->prepare_item_for_response(current($query->posts), $request);
            $booking = $this->prepare_response_for_collection($booking);
        } catch (\Exception $ex) {
            return new WP_Error( 'salon_rest_cannot_view', __( 'Sorry, get resource error ('.sprintf('%s', $ex->getMessage()).').', 'salon-booking-system' ), array( 'status' => 404 ) );
        }

        try {

	    do_action('sln_api_bookings_update_item_before_update_post', $request, $booking_id);

            $cloned_request = clone $request;

	    $cloned_request->set_default_params($booking);

	    $customer_id = $request->get_param('customer_id');

	    if ( ! $customer_id  && $request->get_param('customer_email') ) {
		$customer_id = $this->create_new_customer($request);
	    }

            if ( $customer_id && $booking['customer_id'] != $customer_id ) {
                $customer_data  = $this->get_customer_data_by_id($customer_id);
                $cloned_request->set_default_params(array_merge($cloned_request->get_default_params(), $customer_data));
            }

	    if ( ! $customer_id ) {
		$customer_id = $booking['customer_id'];
	    }

            $data = $this->update_item_post($cloned_request, $booking_id, $customer_id);

        } catch (\Exception $ex) {
            return new WP_Error( 'salon_rest_cannot_update', $ex->getMessage(), array( 'status' => 404 ) );
        }

	return $this->success_response(array(
	    'id'	  => $data['id'],
	    'customer_id' => $data['customer_id']
	));
    }

    public function delete_item( $request )
    {
	$query = $this->get_item_query($request->get_param('id'), $request);

        if ( ! $query->posts ) {
            return new WP_Error( 'salon_rest_cannot_delete', __( 'Sorry, resource not found.', 'salon-booking-system' ), array( 'status' => 404 ) );
        }

        wp_trash_post($request->get_param('id'));

        return $this->success_response();
    }

    public function pay_remaining_amount($request)
    {
        $query = $this->get_item_query($request->get_param('id'), $request);

        if (!$query->posts) {
            return new WP_Error('salon_rest_cannot_pay_remaining_amount', __('Sorry, resource not found.', 'salon-booking-system'), array('status' => 404));
        }

        $booking = new SLN_Wrapper_Booking($request->get_param('id'));
        $mail = $booking->getEmail();

        if ($mail) {
            $args = compact('booking');
            $args['to'] = $mail;
            SLN_Plugin::getInstance()->sendMail('mail/pay_remaining_amount', $args);
            $data = array('success' => 1);
        } else {
            $data = array('error' => __('Please specify an email', 'salon-booking-system'));
        }

        return $this->success_response($data);
    }

    protected function create_item_post($request, $customer_id)
    {
	$bb = new SLN_Wrapper_Booking_Builder(SLN_Plugin::getInstance());

        $bb->setDate($request->get_param('date'));
        $bb->setTime($request->get_param('time'));

        $bb->set('firstname', $request->get_param('customer_first_name'));
        $bb->set('lastname', $request->get_param('customer_last_name'));
        $bb->set('email', $request->get_param('customer_email'));
        $bb->set('phone', $request->get_param('customer_phone'));
        $bb->set('address', $request->get_param('customer_address'));
        $bb->set('discounts', $request->get_param('discounts'));
        $bb->set('note', $request->get_param('note'));
        $bb->set('transaction_id', $request->get_param('transaction_id'));
        $bb->set('admin_note', $request->get_param('admin_note'));

        $services = array();

        foreach (array_filter($request->get_param('services')) as $service) {
            $services[] = array(
                'attendant' => isset($service['assistant_id']) ? $service['assistant_id'] : '',
                'service'   => isset($service['service_id']) ? $service['service_id'] : '',
            );
        }

        $bb->set('services', $services);

	do_action('sln_api_bookings_create_item_post_before_valid', $bb, $request);

	$this->request	   = $request;
	$this->customer_id = $customer_id;

	add_filter( 'sln.booking_builder.getCreateStatus', array( $this, 'get_booking_create_status' ));

	add_filter( 'sln.booking_builder.create.getPostArgs', array( $this, 'get_booking_create_get_post_args' ));

        $bb->create();

	remove_filter( 'sln.booking_builder.getCreateStatus', array( $this, 'get_booking_create_status' ));

	remove_filter( 'sln.booking_builder.create.getPostArgs', array( $this, 'get_booking_create_get_post_args' ));

	$booking = $bb->getLastBooking();

        return array(
	    'id'	  => $booking->getId(),
	    'customer_id' => $booking->getUserId(),
	);
    }

    protected function update_item_post($request, $id, $customer_id)
    {
        $bb = new SLN_Wrapper_Booking_Builder(SLN_Plugin::getInstance());

        $bb->setDate($request->get_param('date'));
        $bb->setTime($request->get_param('time'));

        $services = array();

        foreach (array_filter($request->get_param('services')) as $service) {
            $services[] = array(
                'attendant' => isset($service['assistant_id']) ? $service['assistant_id'] : '',
                'service'   => isset($service['service_id']) ? $service['service_id'] : '',
            );
        }

        $bb->set('services', $services);

	do_action('sln_api_bookings_update_item_post_before_valid', $bb, $request);

        $this->booking_id = $id;

        add_filter( 'sln.repository.booking.processCriteria', array( $this, 'process_bookings_criteria' ));

        remove_filter( 'sln.repository.booking.processCriteria', array( $this, 'process_bookings_criteria' ));

        $booking = $this->prepare_item_for_response($id, $request);

        $prev_status = $booking->getStatus();
        $old_booking_services = $booking->getMeta('services');

        $h = new SLN_Metabox_Helper();
        $is_modified = false;
        $m = SLN_Plugin::getInstance()->messages();

        $meta = array(
            '_sln_booking_date'      => $request->get_param('date'),
            '_sln_booking_time'      => $request->get_param('time'),
            '_sln_booking_firstname' => $request->get_param('customer_first_name'),
            '_sln_booking_lastname'  => $request->get_param('customer_last_name'),
            '_sln_booking_email'     => $request->get_param('customer_email'),
            '_sln_booking_phone'     => $request->get_param('customer_phone'),
            '_sln_booking_address'   => $request->get_param('customer_address'),
            '_sln_booking_services'  => $bb->getBookingServices()->toArrayRecursive(),
            '_sln_booking_discounts' => $request->get_param('discounts'),
            '_sln_booking_note'      => $request->get_param('note'),
            '_sln_booking_transaction_id' => $request->get_param('transaction_id'),
            '_sln_booking_admin_note'   => $request->get_param('admin_note'),
        );

        if($h->isMetaNewForPost($id, $meta) && $prev_status != 'auto-draft') {
            $is_modified = true;
        }

        $name      = $request->get_param('customer_first_name').' '.$request->get_param('customer_last_name');
        $datetime  = SLN_Plugin::getInstance()->format()->datetime($bb->getDateTime());

	$args = array(
            'ID'          => $id,
            'post_title'  => $name.' - '.$datetime,
            'post_type'   => self::POST_TYPE,
            'post_author' => (int)$customer_id,
            'meta_input'  => $meta,
        );

        $id = wp_update_post($args);

        if ( is_wp_error($id) ) {
            throw new \Exception(esc_html__( 'Save post error.', 'salon-booking-system' ));
        }

        $booking = $this->prepare_item_for_response($id, $request);

        do_action('sln.api.booking.pre_eval', $booking, $request->get_param('discounts'));
        $booking->evalBookingServices();
        $booking->evalTotal();
        $booking->evalDuration();
        $booking->setStatus($request->get_param('status'));

        if(!$is_modified) {
            if($prev_status != 'auto-draft' &&
            $old_booking_services != $booking->getMeta('services')) {
                $is_modified = true;
            }
        }

        if ($prev_status != $booking->getStatus()) {
            if($prev_status != 'auto-draft' && in_array($booking->getStatus(), $m->getStatusForSummary())) {
                $is_modified = true; //if booking status was changed to PAID or PAY_LATER from backend, send booking modified notification
            } else {
                $is_modified = false; //status changed email was sent, no need to send booking modified email
            }
        }

        if($is_modified) {
            $m->setDisabled(false);
            $m->sendBookingModified($booking);
        }

	do_action('sln_api_bookings_update_item_post', $id, $bb);

        return array(
	    'id'	  => $booking->getId(),
	    'customer_id' => $booking->getUserId(),
	);
    }


    public function process_bookings_criteria($criteria)
    {
        if ( ! isset($criteria['@wp_query']) ) {
            $criteria['@wp_query'] = array();
        }

        $criteria['@wp_query']['post__not_in'] = array($this->booking_id);

        return $criteria;
    }

    public function get_booking_create_status($status)
    {
	return $this->request->get_param('status') ? $this->request->get_param('status') : $status;
    }

    public function get_booking_create_get_post_args($args)
    {
	$args['post_author'] = (int)$this->customer_id;

	return $args;
    }

    protected function get_item_query($item_id, $request) {

	$prepared_args = array(
            'post_type' => self::POST_TYPE,
            'p'         => $item_id,
        );

	$prepared_args = apply_filters('sln_api_bookings_get_item_query_prepared_args', $prepared_args, $request);

	$query = new WP_Query($prepared_args);

	return $query;
    }

    public function get_item_schema()
    {
        $schema = array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'service',
            'type'       => 'object',
            'properties' => array(
                'id' => array(
                    'description' => __( 'Unique identifier for the resource.', 'salon-booking-system' ),
                    'type'        => 'integer',
                    'context'     => array( 'view', 'edit' ),
                    'arg_options' => array(
                        'readonly'    => true,
                    ),
                ),
                'created' => array(
                    'description' => __( 'Created timestamp for the resource.', 'salon-booking-system' ),
                    'type'        => 'integer',
                    'context'     => array( 'view' ),
                    'arg_options' => array(
                        'readonly' => true,
                    ),
                ),
                'date' => array(
                    'description' => __( 'The date for the resource.', 'salon-booking-system' ),
                    'type'        => 'string',
                    'format'      => 'YYYY-MM-DD',
                    'context'     => array( 'view', 'edit' ),
                    'arg_options' => array(
                        'required'          => true,
                        'validate_callback' => array($this, 'rest_validate_request_arg'),
                        'default'           => current_time('Y-m-d'),
                    ),
                ),
                'time' => array(
                    'description' => __( 'The time for the resource.', 'salon-booking-system' ),
                    'type'        => 'string',
                    'format'      => 'HH:ii',
                    'context'     => array( 'view', 'edit' ),
                    'arg_options' => array(
                        'required'          => true,
                        'validate_callback' => array($this, 'rest_validate_request_arg'),
                        'default'           => current_time('H:i'),
                    ),
                ),
                'status' => array(
                    'description' => __( 'The status for the resource.', 'salon-booking-system' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                    'enum'        => array(
                        'sln-b-pendingpayment', 'sln-b-pending', 'sln-b-paid', 'sln-b-paylater', 'sln-b-canceled', 'sln-b-confirmed', 'sln-b-error'
                    ),
                    'arg_options' => array(
                        'required'  => true,
                    ),
                ),
                'customer_id' => array(
                    'description' => __( 'The customer id for the resource.', 'salon-booking-system' ),
                    'type'        => 'integer',
                    'context'     => array( 'view', 'edit' ),
                    'arg_options' => array(
                        'default' => 0,
                    ),
                ),
                'customer_first_name' => array(
                    'description' => __( 'The customer first name for the resource.', 'salon-booking-system' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                    'arg_options' => array(
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'           => '',
                    ),
                ),
                'customer_last_name' => array(
                    'description' => __( 'The customer last name for the resource.', 'salon-booking-system' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                    'arg_options' => array(
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'           => '',
                    ),
                ),
                'customer_email' => array(
                    'description' => __( 'The customer email for the resource.', 'salon-booking-system' ),
                    'type'        => 'string',
                    'format'      => 'email',
                    'context'     => array( 'view', 'edit' ),
                    'arg_options' => array(
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'           => '',
                        'validate_callback' => array($this, 'rest_validate_empty_string')
                    ),
                ),
                'customer_phone' => array(
                    'description' => __( 'The customer phone for the resource.', 'salon-booking-system' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                    'arg_options' => array(
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'           => '',
                    ),
                ),
                'customer_address' => array(
                    'description' => __( 'The customer address for the resource.', 'salon-booking-system' ),
                    'type'        => 'string',
                    'context'     => array( 'view', 'edit' ),
                    'arg_options' => array(
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'           => '',
                    ),
                ),
                'services' => array(
                    'description' => __( 'The services for the resource.', 'salon-booking-system' ),
                    'type'        => 'array',
                    'context'     => array( 'view', 'edit' ),
                    'items'  => array(
                        'description' => __( 'The service item.', 'salon-booking-system' ),
                        'type'        => 'object',
                        'context'     => array( 'view', 'edit' ),
                        'required'    => array( 'service_id', 'assistant_id' ),
                        'properties'  => array(
                            'start_at' => array(
                                'description' => __( 'The start at.', 'salon-booking-system' ),
                                'type'        => 'string',
                                'format'      => 'HH:ii',
                                'context'     => array( 'view' ),
                                'arg_options' => array(
                                    'readonly' => true,
                                    'default'  => '',
                                ),
                            ),
                            'end_at' => array(
                                'description' => __( 'The end at.', 'salon-booking-system' ),
                                'type'        => 'string',
                                'format'      => 'HH:ii',
                                'context'     => array( 'view' ),
                                'arg_options' => array(
                                    'readonly' => true,
                                    'default'  => '',
                                ),
                            ),
                            'service_id' => array(
                                'description' => __( 'The service id.', 'salon-booking-system' ),
                                'type'        => 'integer',
                                'context'     => array( 'view', 'edit' ),
                                'arg_options' => array(
                                    'required' => true,
                                ),
                            ),
                            'service_name' => array(
                                'description' => __( 'The service name.', 'salon-booking-system' ),
                                'type'        => 'string',
                                'context'     => array( 'view' ),
                            ),
                            'service_price' => array(
                                'description' => __( 'The service price.', 'salon-booking-system' ),
                                'type'        => 'number',
                                'context'     => array( 'view' ),
                            ),
                            'assistant_id' => array(
                                'description' => __( 'The assistant id.', 'salon-booking-system' ),
                                'type'        => 'integer',
                                'context'     => array( 'view', 'edit' ),
                                'arg_options' => array(
                                    'required' => true,
                                ),
                            ),
                            'assistant_name' => array(
                                'description' => __( 'The assistant name.', 'salon-booking-system' ),
                                'type'        => 'string',
                                'context'     => array( 'view' ),
                            ),
                            'resource_id' => array(
                                'description' => __( 'The resource id.', 'salon-booking-system' ),
                                'type'        => 'integer',
                                'context'     => array( 'view', 'edit' ),
                                'arg_options' => array(
                                    'required' => true,
                                ),
                            ),
                            'resource_name' => array(
                                'description' => __( 'The resource name.', 'salon-booking-system' ),
                                'type'        => 'string',
                                'context'     => array( 'view' ),
                            ),
                        ),
                    ),
                    'arg_options' => array(
                        'default'           => array(),
                        'validate_callback' => array($this, 'rest_validate_request_arg'),
                    ),
                ),
                'discounts' => array(
                    'description' => __( 'The discounts ids for the resource.', 'salon-booking-system' ),
                    'type'        => 'array',
                    'items'       => array(
                        'type' => 'integer',
                    ),
                    'context'     => array( 'view', 'edit' ),
                    'arg_options' => array(
                        'default' => array(),
                    ),
                ),
                'duration' => array(
                    'description' => __( 'The duration for the resource.', 'salon-booking-system' ),
                    'type'        => 'string',
                    'format'      => 'HH:ii',
                    'context'     => array( 'view' ),
                    'arg_options' => array(
                        'readonly'          => true,
                        'validate_callback' => array($this, 'rest_validate_request_arg'),
                    ),
                ),
                'amount' => array(
                    'description' => __( 'The amount for the resource.', 'salon-booking-system' ),
                    'type'        => 'number',
                    'context'     => array( 'view' ),
                    'arg_options' => array(
                        'readonly' => true,
                    ),
                ),
                'deposit' => array(
                    'description' => __( 'The deposit for the resource.', 'salon-booking-system' ),
                    'type'        => 'number',
                    'context'     => array( 'view' ),
                    'arg_options' => array(
                        'readonly' => true,
                    ),
                ),
                'paid_remained' => array(
                    'description' => __( 'The paid remained amount for the resource.', 'salon-booking-system' ),
                    'type'        => 'number',
                    'context'     => array( 'view' ),
                    'arg_options' => array(
                        'readonly' => true,
                    ),
                ),
                'currency' => array(
                    'description' => __( 'The currency symbol the resource.', 'salon-booking-system' ),
                    'type'        => 'string',
                    'context'     => array( 'view' ),
                    'arg_options' => array(
                        'readonly' => true,
                    ),
                ),
                'transaction_id' => array(
                    'description' => __( 'The transaction id for the resource.', 'salon-booking-system' ),
                    'type'        => 'array',
                    'items'       => array(
                        'type' => 'string',
                    ),
                    'context'     => array( 'view' ),
                    'arg_options' => array(
                        'readonly'	    => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'	    => '',
                    ),
                ),
                'note' => array(
                    'description' => __( 'The description for the resource.', 'salon-booking-system' ),
                    'type'        => 'string',
                    'context'     => array( 'view' ),
                    'arg_options' => array(
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'           => '',
                    ),
                ),
                'admin_note' => array(
                    'description' => __( 'The admin description for the resource.', 'salon-booking-system' ),
                    'type'        => 'string',
                    'context'     => array( 'view' ),
                    'arg_options' => array(
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'           => '',
                    ),
                ),
            )
        );

        return apply_filters('sln_api_bookings_get_item_schema', $schema);
    }

    /**
     * Get enhanced booking statistics with revenue data and comparisons
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_enhanced_stats( $request )
    {
        global $wpdb;

        $start_date = new \SLN_DateTime($request->get_param('start_date'));
        $end_date = new \SLN_DateTime($request->get_param('end_date'));
        $group_by = $request->get_param('group_by');
        $compare_previous = $request->get_param('compare_previous_period');
        
        // Calculate previous period dates if comparison requested
        $prev_start_date = null;
        $prev_end_date = null;
        if ($compare_previous) {
            $interval = $start_date->diff($end_date);
            $prev_end_date = clone $start_date;
            $prev_end_date->modify('-1 day');
            $prev_start_date = clone $prev_end_date;
            $prev_start_date->sub($interval);
        }

        // Get current period stats
        $current_stats = $this->calculate_period_stats($start_date, $end_date, $group_by, $request);
        
        // Get previous period stats if requested
        $previous_stats = null;
        $comparison = null;
        if ($compare_previous && $prev_start_date && $prev_end_date) {
            $previous_stats = $this->calculate_period_stats($prev_start_date, $prev_end_date, $group_by, $request);
            $comparison = $this->calculate_comparison($current_stats, $previous_stats);
        }

        $response = array(
            'current_period' => $current_stats,
        );

        if ($compare_previous) {
            $response['previous_period'] = $previous_stats;
            $response['comparison'] = $comparison;
        }

        return $this->success_response($response);
    }

    /**
     * Calculate stats for a given period
     *
     * @param \SLN_DateTime $start_date
     * @param \SLN_DateTime $end_date
     * @param string $group_by
     * @param \WP_REST_Request $request
     * @return array
     */
    private function calculate_period_stats($start_date, $end_date, $group_by, $request)
    {
        global $wpdb;

        // Build meta query for filters
        $meta_query = array(
            array(
                'key'     => '_sln_booking_date',
                'value'   => array($start_date->format('Y-m-d'), $end_date->format('Y-m-d')),
                'compare' => 'BETWEEN',
                'type'    => 'DATE',
            ),
        );

        // Add service filter
        if ($services = $request->get_param('services')) {
            $meta_query[] = array(
                'key'     => '_sln_booking_services',
                'value'   => implode('|', array_map(function ($v) {
                    return sprintf('\"service\"\;\i\:%s\;', $v);
                }, $services)),
                'compare' => 'REGEXP',
            );
        }

        // Add shop filter for Multi-Shop support
        $shop_id = (int) $request->get_param('shop');
        if ($shop_id > 0 && class_exists('\SalonMultishop\Addon')) {
            $meta_query[] = array(
                'key'     => '_sln_booking_shop',
                'value'   => $shop_id,
                'compare' => '=',
            );
        }

        // Query bookings - use actual WordPress post status values
        $statuses = $request->get_param('statuses') ?: array(
            SLN_Enum_BookingStatus::PAID,
            SLN_Enum_BookingStatus::PAY_LATER,
            SLN_Enum_BookingStatus::CONFIRMED,
            SLN_Enum_BookingStatus::PENDING_PAYMENT,
            SLN_Enum_BookingStatus::CANCELED,
        );
        
        $query_args = array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => $statuses,
            'posts_per_page' => -1,
            'meta_query'     => $meta_query,
        );

        $bookings_query = new WP_Query($query_args);
        $bookings = $bookings_query->posts;

        // Initialize stats
        $total_revenue = 0.0;
        $total_bookings = 0;
        $by_status = array();
        $timeline = array();
        $customers = array();

        // Process each booking
        foreach ($bookings as $post) {
            $booking = SLN_Plugin::getInstance()->createBooking($post->ID);
            $status = $booking->getStatus();
            $amount = $booking->getAmount();
            $date = $booking->getDate()->format('Y-m-d');
            $customer_id = $booking->getUserId();

            // Count total bookings (excluding canceled)
            if (in_array($status, array(SLN_Enum_BookingStatus::PAID, SLN_Enum_BookingStatus::PAY_LATER, SLN_Enum_BookingStatus::CONFIRMED))) {
                $total_revenue += $amount;
                $total_bookings++;
            }

            // Track by status
            if (!isset($by_status[$status])) {
                $by_status[$status] = array('count' => 0, 'revenue' => 0.0);
            }
            $by_status[$status]['count']++;
            $by_status[$status]['revenue'] += $amount;

            // Track timeline
            $timeline_key = $this->get_timeline_key($date, $group_by);
            if (!isset($timeline[$timeline_key])) {
                $timeline[$timeline_key] = array(
                    'date' => $timeline_key,
                    'revenue' => 0.0,
                    'bookings' => 0,
                    'avg_value' => 0.0,
                );
            }
            
            if (in_array($status, array(SLN_Enum_BookingStatus::PAID, SLN_Enum_BookingStatus::PAY_LATER, SLN_Enum_BookingStatus::CONFIRMED))) {
                $timeline[$timeline_key]['revenue'] += $amount;
                $timeline[$timeline_key]['bookings']++;
            }

            // Track customers
            if ($customer_id && !isset($customers[$customer_id])) {
                $customers[$customer_id] = true;
            }
        }

        // Calculate averages in timeline
        foreach ($timeline as $key => $data) {
            if ($data['bookings'] > 0) {
                $timeline[$key]['avg_value'] = $data['revenue'] / $data['bookings'];
            }
        }

        $avg_booking_value = $total_bookings > 0 ? $total_revenue / $total_bookings : 0.0;

        // Calculate no-show and cancellation metrics
        $canceled_count = isset($by_status[SLN_Enum_BookingStatus::CANCELED]['count']) 
            ? $by_status[SLN_Enum_BookingStatus::CANCELED]['count'] 
            : 0;
        $canceled_revenue = isset($by_status[SLN_Enum_BookingStatus::CANCELED]['revenue']) 
            ? $by_status[SLN_Enum_BookingStatus::CANCELED]['revenue'] 
            : 0.0;
        
        // Note: WordPress doesn't have a separate "no-show" status by default
        // We're treating canceled as the main loss metric
        // If you add a custom no-show status later, add it here
        
        // Calculate total bookings including canceled for rate calculation
        $total_all_bookings = $total_bookings + $canceled_count;
        
        $cancellation_rate = $total_all_bookings > 0 
            ? round(($canceled_count / $total_all_bookings) * 100, 1) 
            : 0.0;

        return array(
            'total_revenue'       => round($total_revenue, 2),
            'total_bookings'      => $total_bookings,
            'avg_booking_value'   => round($avg_booking_value, 2),
            'unique_customers'    => count($customers),
            'new_customers'       => 0, // Would need more complex logic to determine
            'returning_customers' => count($customers), // Simplified
            'by_status'           => $by_status,
            'timeline'            => array_values($timeline),
            
            // No-show and cancellation metrics
            'canceled_count'      => $canceled_count,
            'canceled_revenue'    => round($canceled_revenue, 2),
            'cancellation_rate'   => $cancellation_rate,
        );
    }

    /**
     * Get timeline key based on grouping
     *
     * @param string $date
     * @param string $group_by
     * @return string
     */
    private function get_timeline_key($date, $group_by)
    {
        $dt = new \DateTime($date);
        
        switch ($group_by) {
            case 'day':
                return $dt->format('Y-m-d');
            case 'week':
                return $dt->format('Y') . '-W' . $dt->format('W');
            case 'month':
                return $dt->format('Y-m');
            case 'quarter':
                $quarter = ceil($dt->format('n') / 3);
                return $dt->format('Y') . '-Q' . $quarter;
            case 'year':
                return $dt->format('Y');
            default:
                return $dt->format('Y-m-d');
        }
    }

    /**
     * Calculate comparison between current and previous periods
     *
     * @param array $current
     * @param array $previous
     * @return array
     */
    private function calculate_comparison($current, $previous)
    {
        $calc_change = function($current_val, $previous_val) {
            if ($previous_val == 0) {
                return $current_val > 0 ? 100.0 : 0.0;
            }
            return round((($current_val - $previous_val) / $previous_val) * 100, 2);
        };

        return array(
            'revenue_change_pct'     => $calc_change($current['total_revenue'], $previous['total_revenue']),
            'bookings_change_pct'    => $calc_change($current['total_bookings'], $previous['total_bookings']),
            'avg_value_change_pct'   => $calc_change($current['avg_booking_value'], $previous['avg_booking_value']),
            'customers_change_pct'   => $calc_change($current['unique_customers'], $previous['unique_customers']),
        );
    }

    /**
     * Get peak times analysis
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_peak_times( $request )
    {
        $start_date = new \SLN_DateTime($request->get_param('start_date'));
        $end_date = new \SLN_DateTime($request->get_param('end_date'));
        $shop_id = (int) $request->get_param('shop');

        // Build meta query
        $meta_query = array(
            array(
                'key'     => '_sln_booking_date',
                'value'   => array($start_date->format('Y-m-d'), $end_date->format('Y-m-d')),
                'compare' => 'BETWEEN',
                'type'    => 'DATE',
            ),
        );

        // Add shop filter for Multi-Shop support
        if ($shop_id > 0 && class_exists('\SalonMultishop\Addon')) {
            $meta_query[] = array(
                'key'     => '_sln_booking_shop',
                'value'   => $shop_id,
                'compare' => '=',
            );
        }

        // Query bookings
        $query_args = array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => array(
                SLN_Enum_BookingStatus::PAID,
                SLN_Enum_BookingStatus::PAY_LATER,
                SLN_Enum_BookingStatus::CONFIRMED,
            ),
            'posts_per_page' => -1,
            'meta_query'     => $meta_query,
        );

        $bookings_query = new WP_Query($query_args);
        
        // Initialize heatmap data structures
        $daily_heatmap = array(); // day_of_week => count
        $hourly_heatmap = array(); // day_of_week => hour => count
        
        // Initialize arrays (1=Monday to 7=Sunday)
        for ($day = 1; $day <= 7; $day++) {
            $daily_heatmap[$day] = 0;
            $hourly_heatmap[$day] = array();
            for ($hour = 0; $hour < 24; $hour++) {
                $hourly_heatmap[$day][$hour] = 0;
            }
        }

        // Process bookings
        foreach ($bookings_query->posts as $post) {
            $booking = SLN_Plugin::getInstance()->createBooking($post->ID);
            $booking_date = $booking->getStartsAt();
            
            if ($booking_date) {
                $day_of_week = (int)$booking_date->format('N'); // 1 (Monday) to 7 (Sunday)
                $hour = (int)$booking_date->format('G'); // 0-23
                
                $daily_heatmap[$day_of_week]++;
                $hourly_heatmap[$day_of_week][$hour]++;
            }
        }

        return $this->success_response(array(
            'daily_heatmap'  => $daily_heatmap,
            'hourly_heatmap' => $hourly_heatmap,
        ));
    }

    /**
     * Get cancellation analytics
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_cancellations( $request )
    {
        global $wpdb;

        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');

        // Query all bookings in period
        $all_query_args = array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => array('paid', 'pay_later', 'confirmed', 'canceled'),
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => '_sln_booking_date',
                    'value'   => array($start_date, $end_date),
                    'compare' => 'BETWEEN',
                    'type'    => 'DATE',
                ),
            ),
        );

        // Query canceled bookings
        $canceled_query_args = $all_query_args;
        $canceled_query_args['post_status'] = array('canceled');

        $all_bookings = new WP_Query($all_query_args);
        $canceled_bookings = new WP_Query($canceled_query_args);

        $total_count = $all_bookings->post_count;
        $canceled_count = $canceled_bookings->post_count;
        $cancellation_rate = $total_count > 0 ? round(($canceled_count / $total_count) * 100, 2) : 0.0;

        // Analyze by service
        $by_service = array();
        foreach ($canceled_bookings->posts as $post) {
            $booking = SLN_Plugin::getInstance()->createBooking($post->ID);
            foreach ($booking->getBookingServices()->getItems() as $bookingService) {
                $service = $bookingService->getService();
                $service_id = $service->getId();
                
                if (!isset($by_service[$service_id])) {
                    $by_service[$service_id] = array(
                        'service_id'   => $service_id,
                        'service_name' => $service->getName(),
                        'cancellations'=> 0,
                        'rate'         => 0.0,
                    );
                }
                $by_service[$service_id]['cancellations']++;
            }
        }

        // Calculate rates per service
        foreach ($by_service as $service_id => $data) {
            // Count total bookings for this service
            $service_total_query = array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => array('paid', 'pay_later', 'confirmed', 'canceled'),
                'posts_per_page' => -1,
                'meta_query'     => array(
                    array(
                        'key'     => '_sln_booking_date',
                        'value'   => array($start_date, $end_date),
                        'compare' => 'BETWEEN',
                        'type'    => 'DATE',
                    ),
                    array(
                        'key'     => '_sln_booking_services',
                        'value'   => sprintf('\"service\"\;\i\:%s\;', $service_id),
                        'compare' => 'LIKE',
                    ),
                ),
            );
            
            $service_total = new WP_Query($service_total_query);
            $service_total_count = $service_total->post_count;
            
            if ($service_total_count > 0) {
                $by_service[$service_id]['rate'] = round(($data['cancellations'] / $service_total_count) * 100, 2);
            }
        }

        return $this->success_response(array(
            'cancellation_rate' => $cancellation_rate,
            'total_canceled'    => $canceled_count,
            'by_service'        => array_values($by_service),
            'by_reason'         => array(
                // This would require additional meta fields to track cancellation reasons
                'customer_request' => 0,
                'no_show'          => 0,
                'schedule_conflict'=> 0,
            ),
        ));
    }

    /**
     * Get utilization metrics - peak hours/days and capacity usage
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_utilization($request)
    {
        $start_date = new \SLN_DateTime($request->get_param('start_date'));
        $end_date = new \SLN_DateTime($request->get_param('end_date'));
        $shop_id = (int) $request->get_param('shop');

        // Build meta query
        $meta_query = array(
            array(
                'key'     => '_sln_booking_date',
                'value'   => array($start_date->format('Y-m-d'), $end_date->format('Y-m-d')),
                'compare' => 'BETWEEN',
                'type'    => 'DATE',
            ),
        );

        // Add shop filter for Multi-Shop support
        if ($shop_id > 0 && class_exists('\SalonMultishop\Addon')) {
            $meta_query[] = array(
                'key'     => '_sln_booking_shop',
                'value'   => $shop_id,
                'compare' => '=',
            );
        }

        // Query all bookings in the period
        $query_args = array(
            'post_type'      => SLN_Plugin::POST_TYPE_BOOKING,
            'post_status'    => array(
                \SLN_Enum_BookingStatus::PAID,
                \SLN_Enum_BookingStatus::PAY_LATER,
                \SLN_Enum_BookingStatus::CONFIRMED,
            ),
            'posts_per_page' => -1,
            'meta_query'     => $meta_query,
        );

        $bookings_query = new \WP_Query($query_args);
        $bookings = $bookings_query->posts;

        // Initialize tracking arrays
        $by_day_of_week = array(
            'Monday'    => array('bookings' => 0, 'revenue' => 0.0, 'hours' => 0),
            'Tuesday'   => array('bookings' => 0, 'revenue' => 0.0, 'hours' => 0),
            'Wednesday' => array('bookings' => 0, 'revenue' => 0.0, 'hours' => 0),
            'Thursday'  => array('bookings' => 0, 'revenue' => 0.0, 'hours' => 0),
            'Friday'    => array('bookings' => 0, 'revenue' => 0.0, 'hours' => 0),
            'Saturday'  => array('bookings' => 0, 'revenue' => 0.0, 'hours' => 0),
            'Sunday'    => array('bookings' => 0, 'revenue' => 0.0, 'hours' => 0),
        );

        $by_hour = array();
        for ($h = 0; $h < 24; $h++) {
            $by_hour[$h] = array('bookings' => 0, 'revenue' => 0.0);
        }

        $total_booked_minutes = 0;
        $total_bookings = count($bookings);

        // Process bookings
        foreach ($bookings as $post) {
            $booking = \SLN_Plugin::getInstance()->createBooking($post->ID);
            $starts_at = $booking->getStartsAt();
            
            if (!$starts_at) continue;

            $amount = $booking->getAmount();
            $day_name = $starts_at->format('l'); // Monday, Tuesday, etc.
            $hour = (int) $starts_at->format('G'); // 0-23

            // Update day of week stats
            if (isset($by_day_of_week[$day_name])) {
                $by_day_of_week[$day_name]['bookings']++;
                $by_day_of_week[$day_name]['revenue'] += $amount;
                
                // Calculate booking duration in minutes
                $duration_minutes = 0;
                foreach ($booking->getBookingServices()->getItems() as $bookingService) {
                    $service = $bookingService->getService();
                    if ($service) {
                        $duration = $service->getDuration();
                        $minutes = \SLN_Func::getMinutesFromDuration($duration);
                        $duration_minutes += $minutes;
                    }
                }
                $by_day_of_week[$day_name]['hours'] += $duration_minutes;
                $total_booked_minutes += $duration_minutes;
            }

            // Update hour stats
            if (isset($by_hour[$hour])) {
                $by_hour[$hour]['bookings']++;
                $by_hour[$hour]['revenue'] += $amount;
            }
        }

        // Convert booked minutes to hours
        foreach ($by_day_of_week as $day => &$stats) {
            $stats['hours'] = round($stats['hours'] / 60, 1);
        }
        unset($stats);

        // Calculate period stats
        $period_days = $end_date->diff($start_date)->days + 1;
        $total_booked_hours = round($total_booked_minutes / 60, 1);

        // Get salon settings for business hours
        $plugin = \SLN_Plugin::getInstance();
        $settings = $plugin->getSettings();
        
        // Default business hours (8 AM - 6 PM = 10 hours)
        $business_hours_per_day = 10;
        
        // Try to calculate from settings
        $opening_time = $settings->get('gen_timetable_from');
        $closing_time = $settings->get('gen_timetable_to');
        
        if ($opening_time && $closing_time) {
            $open = \DateTime::createFromFormat('H:i', $opening_time);
            $close = \DateTime::createFromFormat('H:i', $closing_time);
            if ($open && $close) {
                $diff = $close->diff($open);
                $business_hours_per_day = $diff->h + ($diff->i / 60);
            }
        }

        // Count working days in period (exclude days salon is closed)
        $working_days = $period_days; // Simplified - would need to check salon's working days
        
        // Calculate total available hours
        $total_available_hours = $working_days * $business_hours_per_day;
        
        // Calculate utilization rate
        $utilization_rate = $total_available_hours > 0 
            ? round(($total_booked_hours / $total_available_hours) * 100, 1)
            : 0.0;

        // Find peak day and hour
        $peak_day = '';
        $peak_day_bookings = 0;
        foreach ($by_day_of_week as $day => $stats) {
            if ($stats['bookings'] > $peak_day_bookings) {
                $peak_day = $day;
                $peak_day_bookings = $stats['bookings'];
            }
        }

        $peak_hour = 0;
        $peak_hour_bookings = 0;
        foreach ($by_hour as $hour => $stats) {
            if ($stats['bookings'] > $peak_hour_bookings) {
                $peak_hour = $hour;
                $peak_hour_bookings = $stats['bookings'];
            }
        }

        // Format peak hour for display
        $peak_hour_formatted = sprintf('%02d:00 - %02d:00', $peak_hour, $peak_hour + 1);

        // Calculate average bookings per day
        $avg_bookings_per_day = $working_days > 0 
            ? round($total_bookings / $working_days, 1) 
            : 0;

        return $this->success_response(array(
            'utilization_rate'       => $utilization_rate,
            'total_booked_hours'     => $total_booked_hours,
            'total_available_hours'  => round($total_available_hours, 1),
            'business_hours_per_day' => $business_hours_per_day,
            'avg_bookings_per_day'   => $avg_bookings_per_day,
            'peak_day'               => $peak_day,
            'peak_day_bookings'      => $peak_day_bookings,
            'peak_hour'              => $peak_hour_formatted,
            'peak_hour_bookings'     => $peak_hour_bookings,
            'by_day_of_week'         => $by_day_of_week,
            'by_hour'                => $by_hour,
            'period_days'            => $period_days,
            'working_days'           => $working_days,
        ));
    }

}