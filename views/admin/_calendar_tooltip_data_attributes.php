<?php

/**
 * Common tooltip data attributes for calendar views
 * This file centralizes all tooltip data attributes to avoid duplication
 */

/**
 * Generate common tooltip data attributes for calendar events
 * 
 * @param object $event The calendar event object (bsEvent or booking)
 * @param bool $isPro Whether Pro features are enabled
 * @param string $eventType Type of event ('bsEvent' or 'booking')
 * @return string HTML data attributes
 */
function generateTooltipDataAttributes($event, $isPro, $eventType = 'bsEvent')
{
  $plugin = SLN_Plugin::getInstance();
  $attributes = [];

  // Common mandatory attributes
  $attributes[] = 'data-sln-tooltip="true"';
  $attributes[] = 'data-tooltip-type="booking"';
  $attributes[] = 'data-tooltip-id="' . esc_attr($event->id) . '"';
  $attributes[] = 'data-event-id="' . esc_attr($event->id) . '"';

  // Title handling (different for day vs week view)
  if ($eventType === 'bsEvent') {
    $title = $event->main ? strip_tags($event->title) : '';
  } else {
    $title = strip_tags($event->title);
  }
  $attributes[] = 'data-event-title="' . esc_attr($title) . '"';

  // Modern tooltip title (clean title for our new system)
  $modernTitle = generateModernTooltipTitle($event, $eventType);
  $attributes[] = 'data-modern-tooltip-title="' . esc_attr($modernTitle) . '"';

  // Amount (always present)
  $attributes[] = 'data-event-amount="' . esc_attr($event->amount) . '"';

  // Optional Pro features with error handling
  if ($isPro) {
    // Discount
    if (isset($event->discount)) {
      $attributes[] = 'data-event-discount="' . esc_attr($event->discount) . '"';
    }

    // Deposit
    if (isset($event->deposit)) {
      $attributes[] = 'data-event-deposit="' . esc_attr($event->deposit) . '"';
    }

    // Due
    if (isset($event->due)) {
      $attributes[] = 'data-event-due="' . esc_attr($event->due) . '"';
    }

    // Tax (requires booking object)
    try {
      $booking = new SLN_Wrapper_Booking($event->id);
      if (method_exists($booking, 'getTaxFromTotal')) {
        $tax = $plugin->format()->money($booking->getTaxFromTotal(), false, true);
        $attributes[] = 'data-event-tax="' . esc_attr($tax) . '"';
      }
    } catch (Exception $e) {
      // Silently handle errors
    }

    // Transaction Fee (requires booking object)
    try {
      $booking = new SLN_Wrapper_Booking($event->id);
      if (method_exists($booking, 'getAmount')) {
        $fee = $plugin->format()->money(SLN_Helper_TransactionFee::getFee($booking->getAmount()), false, true);
        $attributes[] = 'data-event-transaction-fee="' . esc_attr($fee) . '"';
      }
    } catch (Exception $e) {
      // Silently handle errors
    }
  }

  // Customer ID (always present)
  $attributes[] = 'data-customer-id="' . esc_attr($event->customerId) . '"';

  // Customer Phone with Country Code (optional - check if exists)
  try {
    $booking = new SLN_Wrapper_Booking($event->id);
    if (method_exists($booking, 'getPhone')) {
      $phone = $booking->getPhone();
      $countryCode = '';

      // Get country code if available
      if (method_exists($booking, 'getSmsPrefix')) {
        $countryCode = $booking->getSmsPrefix();
      }

      if (!empty($phone)) {
        // Combine country code with phone number
        $fullPhone = !empty($countryCode) ? $countryCode . $phone : $phone;
        $attributes[] = 'data-customer-phone="' . esc_attr($fullPhone) . '"';
      }
    }
  } catch (Exception $e) {
    // Silently handle errors
  }

  // Customer Email (optional - check if exists)
  try {
    $booking = new SLN_Wrapper_Booking($event->id);
    if (method_exists($booking, 'getEmail')) {
      $email = $booking->getEmail();
      if (!empty($email)) {
        $attributes[] = 'data-customer-email="' . esc_attr($email) . '"';
      }
    }
  } catch (Exception $e) {
    // Silently handle errors
  }

  // Booking Channel/Origin (optional - check if exists)
  try {
    $booking = new SLN_Wrapper_Booking($event->id);
    if (method_exists($booking, 'getOrigin')) {
      $origin = $booking->getOrigin();
      if (!empty($origin)) {
        // Convert old labels to new labels for display
        $displayLabel = SLN_Enum_BookingOrigin::getLabel($origin);
        
        // Add WordPress username for Back-end and Web app origins
        // Use created_by_user_id (admin who created) not post_author (customer)
        if ($displayLabel === 'Back-end' || $displayLabel === 'Web app') {
          $creator_user_id = get_post_meta($event->id, '_sln_booking_created_by_user_id', true);
          
          // Fallback: If no creator ID, check if post_author is an admin/staff user
          if (!$creator_user_id) {
            $post = get_post($event->id);
            if ($post && $post->post_author) {
              $post_author_user = get_userdata($post->post_author);
              if ($post_author_user) {
                // Check if post_author is admin/staff (not just customer)
                $user_roles = $post_author_user->roles;
                if (array_intersect($user_roles, ['administrator', 'shop_manager', 'sln_staff', 'sln_worker'])) {
                  $creator_user_id = $post->post_author;
                }
              }
            }
          }
          
          // Display username if we found a creator
          if ($creator_user_id) {
            $user = get_userdata($creator_user_id);
            if ($user) {
              $username = $user->display_name ?: $user->user_login;
              $displayLabel .= ' (' . $username . ')';
            }
          }
        }
        
        $attributes[] = 'data-booking-channel="' . esc_attr($displayLabel) . '"';
      }
    }
  } catch (Exception $e) {
    // Silently handle errors
  }

  // Customer Note (optional - check if exists)
  try {
    $booking = new SLN_Wrapper_Booking($event->id);
    if (method_exists($booking, 'getNote')) {
      $note = $booking->getNote();
      if (!empty($note)) {
        $attributes[] = 'data-booking-note="' . esc_attr($note) . '"';
      }
    }
  } catch (Exception $e) {
    // Silently handle errors
  }

  // Shop/Store (optional - multishop add-on feature)
  $shopId = get_post_meta($event->id, '_sln_booking_shop', true);
  if (!empty($shopId)) {
    // Get shop name if possible
    $shopName = get_the_title($shopId);
    if (!empty($shopName)) {
      $attributes[] = 'data-booking-shop="' . esc_attr($shopName) . '"';
    } else {
      $attributes[] = 'data-booking-shop="' . esc_attr('Shop #' . $shopId) . '"';
    }
  }

  // Booking Status with Color
  try {
    $booking = new SLN_Wrapper_Booking($event->id);
    if (method_exists($booking, 'getStatus')) {
      $statusKey = $booking->getStatus();
      $statusLabel = SLN_Enum_BookingStatus::getLabel($statusKey);
      
      // Status colors (matches booking stats colors)
      $statusColors = [
        'sln-b-paid' => '#6aa84f',
        'sln-b-confirmed' => '#6aa84f',
        'sln-b-paylater' => '#6d9eeb',
        'sln-b-pending' => '#f58120',
        'sln-b-pendingpayment' => '#f58120',
        'sln-b-canceled' => '#e54747',
        'sln-b-error' => '#e54747',
      ];
      
      $statusColor = isset($statusColors[$statusKey]) ? $statusColors[$statusKey] : '#1b1b21';
      
      // Check for no-show
      $isNoShow = get_post_meta($event->id, 'no_show', true) == 1;
      if ($isNoShow) {
        $statusColor = '#1b1b21';
      }
      
      $attributes[] = 'data-event-status="' . esc_attr($statusLabel) . '"';
      $attributes[] = 'data-event-status-color="' . esc_attr($statusColor) . '"';
    }
  } catch (Exception $e) {
    // Silently handle errors
  }

  // Pro status
  $attributes[] = 'data-is-pro="' . ($isPro ? 'true' : 'false') . '"';

  // No-show status
  $noShow = get_post_meta($event->id, 'no_show', true) == 1;
  $attributes[] = 'data-no-show="' . ($noShow ? 'true' : 'false') . '"';

  // Delete URL (for trash/delete action)
  $deleteUrl = get_delete_post_link($event->id);
  $attributes[] = 'data-delete-url="' . esc_attr($deleteUrl) . '"';

  // Duration (always present)
  // if ($eventType === 'bsEvent') {
  //   try {
  //     $booking = new SLN_Wrapper_Booking($event->id);
  //     if (method_exists($booking, 'getDuration')) {
  //       $duration = $booking->getDuration()->format('H:i');
  //       $attributes[] = 'data-event-duration="' . esc_attr($duration) . '"';
  //     }
  //   } catch (Exception $e) {
  //     // Silently handle errors
  //   }
  // } else {
  //   if (method_exists($event, 'getDuration')) {
  //     $duration = $event->getDuration()->format('H:i');
  //     $attributes[] = 'data-event-duration="' . esc_attr($duration) . '"';
  //   }
  // }

  // Status (always present)
  // if ($eventType === 'bsEvent') {
  //   try {
  //     $booking = new SLN_Wrapper_Booking($event->id);
  //     if (method_exists($booking, 'getStatus')) {
  //       $status = $booking->getStatus();
  //       $attributes[] = 'data-event-status="' . esc_attr($status) . '"';
  //     }
  //   } catch (Exception $e) {
  //     // Silently handle errors
  //   }
  // } else {
  //   if (method_exists($event, 'getStatus')) {
  //     $status = $event->getStatus();
  //     $attributes[] = 'data-event-status="' . esc_attr($status) . '"';
  //   }
  // }

  // Tips (Pro feature)
  // if ($isPro) {
  //   if ($eventType === 'bsEvent') {
  //     try {
  //       $booking = new SLN_Wrapper_Booking($event->id);
  //       if (method_exists($booking, 'getTips')) {
  //         $tips = $booking->getTips();
  //         if ($tips > 0) {
  //           $tipsFormatted = $plugin->format()->money($tips, false, true);
  //           $attributes[] = 'data-event-tips="' . esc_attr($tipsFormatted) . '"';
  //         }
  //       }
  //     } catch (Exception $e) {
  //       // Silently handle errors
  //     }
  //   } else {
  //     if (method_exists($event, 'getTips')) {
  //       $tips = $event->getTips();
  //       if ($tips > 0) {
  //         $tipsFormatted = $plugin->format()->money($tips, false, true);
  //         $attributes[] = 'data-event-tips="' . esc_attr($tipsFormatted) . '"';
  //       }
  //     }
  //   }
  // }

  // Service Names (always present)
  // if ($eventType === 'bsEvent') {
  //   try {
  //     $booking = new SLN_Wrapper_Booking($event->id);
  //     if (method_exists($booking, 'getBookingServices')) {
  //       $serviceNames = [];
  //       foreach ($booking->getBookingServices()->getItems() as $bookingService) {
  //         $serviceNames[] = $bookingService->getService()->getName();
  //       }
  //       if (!empty($serviceNames)) {
  //         $attributes[] = 'data-event-services="' . esc_attr(implode(', ', $serviceNames)) . '"';
  //       }
  //     }
  //   } catch (Exception $e) {
  //     // Silently handle errors
  //   }
  // } else {
  //   if (method_exists($event, 'getBookingServices')) {
  //     $serviceNames = [];
  //     foreach ($event->getBookingServices()->getItems() as $bookingService) {
  //       $serviceNames[] = $bookingService->getService()->getName();
  //     }
  //     if (!empty($serviceNames)) {
  //       $attributes[] = 'data-event-services="' . esc_attr(implode(', ', $serviceNames)) . '"';
  //     }
  //   }
  // }

  // Attendant Names (always present)
  // if ($eventType === 'bsEvent') {
  //   try {
  //     $booking = new SLN_Wrapper_Booking($event->id);
  //     if (method_exists($booking, 'getBookingServices')) {
  //       $attendantNames = [];
  //       foreach ($booking->getBookingServices()->getItems() as $bookingService) {
  //         $attendant = $bookingService->getAttendant();
  //         if ($attendant) {
  //           if (is_array($attendant)) {
  //             foreach ($attendant as $att) {
  //               $attendantNames[] = $att->getName();
  //             }
  //           } else {
  //             $attendantNames[] = $attendant->getName();
  //           }
  //         }
  //       }
  //       if (!empty($attendantNames)) {
  //         $attributes[] = 'data-event-attendants="' . esc_attr(implode(', ', $attendantNames)) . '"';
  //       }
  //     }
  //   } catch (Exception $e) {
  //     // Silently handle errors
  //   }
  // } else {
  //   if (method_exists($event, 'getBookingServices')) {
  //     $attendantNames = [];
  //     foreach ($event->getBookingServices()->getItems() as $bookingService) {
  //       $attendant = $bookingService->getAttendant();
  //       if ($attendant) {
  //         if (is_array($attendant)) {
  //           foreach ($attendant as $att) {
  //             $attendantNames[] = $att->getName();
  //           }
  //         } else {
  //           $attendantNames[] = $attendant->getName();
  //         }
  //       }
  //     }
  //     if (!empty($attendantNames)) {
  //       $attributes[] = 'data-event-attendants="' . esc_attr(implode(', ', $attendantNames)) . '"';
  //     }
  //   }
  // }

  // Accessibility attributes
  $attributes[] = 'role="button"';
  $attributes[] = 'tabindex="0"';

  // Aria label
  $ariaLabel = sprintf(__('Booking: %s', 'salon-booking-system'), $title);
  $attributes[] = 'aria-label="' . esc_attr($ariaLabel) . '"';

  // Tooltip labels (translated)
  $attributes[] = 'data-label-total-amount="' . esc_attr(__('Total amount', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-booking-id="' . esc_attr(__('ID', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-phone="' . esc_attr(__('Phone', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-email="' . esc_attr(__('Email', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-channel="' . esc_attr(__('Channel', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-customer-note="' . esc_attr(__('Customer Note', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-shop="' . esc_attr(__('Shop', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-discount="' . esc_attr(__('Discount', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-deposit="' . esc_attr(__('Paid deposit', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-due="' . esc_attr(__('To be paid', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-tax="' . esc_attr(__('Tax', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-transaction-fee="' . esc_attr(__('Transaction fee', 'salon-booking-system')) . '"';

  // Action button labels (translated)
  $attributes[] = 'data-label-edit="' . esc_attr(__('Edit booking', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-no-show="' . esc_attr(__('Toggle no-show status', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-customer="' . esc_attr(__('View customer', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-delete="' . esc_attr(__('Delete booking', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-duplicate="' . esc_attr(__('Duplicate booking', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-duplicate-pro="' . esc_attr(__('Duplicate booking (Pro feature)', 'salon-booking-system')) . '"';

  // Additional tooltip labels (translated)
  $attributes[] = 'data-label-duration="' . esc_attr(__('Duration', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-status="' . esc_attr(__('Status', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-services="' . esc_attr(__('Services', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-attendants="' . esc_attr(__('Attendants', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-tips="' . esc_attr(__('Tips', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-close="' . esc_attr(__('Close tooltip', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-confirm-delete="' . esc_attr(__('Are you sure?', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-yes-delete="' . esc_attr(__('Yes, delete', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-cancel="' . esc_attr(__('Cancel', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-booking-details="' . esc_attr(__('Booking Details', 'salon-booking-system')) . '"';

  // Free version promo labels
  $attributes[] = 'data-label-unlock-feature="' . esc_attr(__('unlock this feature for', 'salon-booking-system')) . '"';
  $attributes[] = 'data-label-year="' . esc_attr(__('year', 'salon-booking-system')) . '"';

  return implode("\n                    ", $attributes);
}

/**
 * Generate a clean booking title for modern tooltips
 * 
 * @param object $event The event object (either $bsEvent from day view or $booking from week view).
 * @param string $eventType A string indicating the type of event object ('bsEvent' or 'booking').
 * @return string Clean booking title
 */
function generateBookingTitle($event, $eventType = 'bsEvent')
{
  if ($eventType === 'bsEvent') {
    // For day view, create booking object from event ID
    try {
      $booking = new SLN_Wrapper_Booking($event->id);
      return $booking->getDisplayName();
    } catch (Exception $e) {
      return $event->title;
    }
  } else {
    // For week view, use existing booking object
    return $event->getDisplayName();
  }
}

/**
 * Generate a clean title specifically for the modern tooltip system
 * This function is completely separate from the old tooltip system
 * 
 * @param object $event The event object (either $bsEvent from day view or $booking from week view).
 * @param string $eventType A string indicating the type of event object ('bsEvent' or 'booking').
 * @return string Clean title for modern tooltip
 */
function generateModernTooltipTitle($event, $eventType = 'bsEvent')
{
  if ($eventType === 'bsEvent') {
    // For day view, create booking object from event ID
    try {
      $booking = new SLN_Wrapper_Booking($event->id);
      return $booking->getDisplayName();
    } catch (Exception $e) {
      // Fallback to clean event title
      return $event->main ? strip_tags($event->title) : 'Booking Details';
    }
  } else {
    // For week view, use existing booking object
    // Check if the method exists (in case it's a CalendarEvent object instead)
    if (method_exists($event, 'getDisplayName')) {
      return $event->getDisplayName();
    } else {
      // Fallback to title property for CalendarEvent objects
      return isset($event->title) ? strip_tags($event->title) : 'Booking Details';
    }
  }
}
