<?php
/**
 * Plugin Name:       Bindura Science Academy - School Management Extension
 * Description:       BSA-specific security hardening and extensions for the Weblizar School Management plugin.
 * Version:           0.16.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Bindura Science Academy
 * License:           GPL-2.0-or-later
 * Text Domain:       bsa-school-management
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

define( 'BSA_SM_VERSION', '0.16.1' );
define( 'BSA_SM_FILE', __FILE__ );
define( 'BSA_SM_DIR', plugin_dir_path( __FILE__ ) );

/*
 * Weblizar School Management version this extension has been reviewed against.
 * The access guard reads Weblizar tables directly, so a different major.minor
 * version must be re-reviewed (see docs/architecture.md).
 */
define( 'BSA_SM_TESTED_WLSM', '10.3' );

require_once BSA_SM_DIR . 'includes/class-bsa-hardening.php';
require_once BSA_SM_DIR . 'includes/class-bsa-clean-urls.php';
require_once BSA_SM_DIR . 'includes/class-bsa-access-guard.php';
require_once BSA_SM_DIR . 'includes/class-bsa-compat.php';
require_once BSA_SM_DIR . 'includes/class-bsa-admission-records.php';
require_once BSA_SM_DIR . 'includes/class-bsa-portal.php';
require_once BSA_SM_DIR . 'includes/class-bsa-finance.php';
require_once BSA_SM_DIR . 'includes/class-bsa-school.php';
require_once BSA_SM_DIR . 'includes/class-bsa-terms.php';
require_once BSA_SM_DIR . 'includes/class-bsa-register.php';
require_once BSA_SM_DIR . 'includes/class-bsa-grades.php';
require_once BSA_SM_DIR . 'includes/class-bsa-marks.php';
require_once BSA_SM_DIR . 'includes/class-bsa-reports.php';
require_once BSA_SM_DIR . 'includes/class-bsa-family.php';
require_once BSA_SM_DIR . 'includes/class-bsa-accounts.php';
require_once BSA_SM_DIR . 'includes/class-bsa-dashboard.php';
require_once BSA_SM_DIR . 'includes/class-bsa-staff-area.php';
require_once BSA_SM_DIR . 'includes/class-bsa-results.php';
require_once BSA_SM_DIR . 'includes/class-bsa-entry.php';
require_once BSA_SM_DIR . 'includes/class-bsa-intranet.php';
require_once BSA_SM_DIR . 'includes/class-bsa-import.php';
require_once BSA_SM_DIR . 'includes/class-bsa-streams.php';
require_once BSA_SM_DIR . 'includes/class-bsa-rollover.php';
require_once BSA_SM_DIR . 'includes/class-bsa-whatsapp.php';

BSA_Hardening::init();
BSA_Clean_Urls::init();
BSA_Access_Guard::init();
BSA_Compat::init();
BSA_Admission_Records::init();
BSA_Portal::init();
BSA_Finance::init();
BSA_Terms::init();
BSA_Register::init();
BSA_Marks::init();
BSA_Reports::init();
BSA_Family::init();
BSA_Accounts::init();
BSA_Dashboard::init();
BSA_Staff_Area::init();
BSA_Results::init();
BSA_Intranet::init();
BSA_Import::init();
BSA_Streams::init();
BSA_Rollover::init();
BSA_WhatsApp::init();
