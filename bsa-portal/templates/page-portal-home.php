<?php
/**
 * Template Name: Portal home
 *
 * Landing page of the school portal. Copy is limited to what the portal actually
 * offers; contact details are the school's published ones (docs/decisions-log.md).
 *
 * @package BSA_Portal
 */

defined( 'ABSPATH' ) || exit;

$bsa_account_url = class_exists( 'BSA_Portal' ) ? BSA_Portal::account_url() : home_url( '/' );
$bsa_user_id     = get_current_user_id();
$bsa_is_staff    = $bsa_user_id && class_exists( 'BSA_Access_Guard' ) && BSA_Access_Guard::is_staff( $bsa_user_id );
$bsa_logo_id     = (int) get_theme_mod( 'custom_logo' );

if ( ! $bsa_user_id ) {
	$bsa_cta = array( $bsa_account_url, __( 'Sign in', 'bsa-portal' ) );
} elseif ( $bsa_is_staff ) {
	$bsa_cta = array( admin_url(), __( 'Open staff dashboard', 'bsa-portal' ) );
} else {
	$bsa_cta = array( $bsa_account_url, __( 'Open my account', 'bsa-portal' ) );
}

get_header();
?>
<div class="bsa-home">
	<section class="bsa-home__hero">
		<div class="bsa-container bsa-home__hero-inner">
			<div class="bsa-home__intro">
				<h1 class="bsa-home__title"><?php esc_html_e( 'Bindura Science Academy School Portal', 'bsa-portal' ); ?></h1>
				<p class="bsa-home__lead"><?php esc_html_e( 'Fee invoices and payments, attendance, homework, results and school notices for BSA learners and parents.', 'bsa-portal' ); ?></p>
				<p class="bsa-home__actions">
					<a class="bsa-button" href="<?php echo esc_url( $bsa_cta[0] ); ?>"><?php echo esc_html( $bsa_cta[1] ); ?></a>
				</p>
			</div>
			<?php if ( $bsa_logo_id ) : ?>
				<div class="bsa-home__crest">
					<?php echo wp_get_attachment_image( $bsa_logo_id, 'medium', false, array( 'alt' => esc_attr__( 'Bindura Science Academy crest', 'bsa-portal' ), 'loading' => 'eager' ) ); ?>
				</div>
			<?php endif; ?>
		</div>
	</section>

	<section class="bsa-home__details">
		<div class="bsa-container bsa-home__grid">
			<div class="bsa-home__features">
				<h2><?php esc_html_e( 'What you can do here', 'bsa-portal' ); ?></h2>
				<ul class="bsa-checklist">
					<li><?php esc_html_e( 'View fee invoices, the fee structure and your payment history.', 'bsa-portal' ); ?></li>
					<li><?php esc_html_e( 'Check attendance and send leave requests.', 'bsa-portal' ); ?></li>
					<li><?php esc_html_e( 'See homework, study materials and the class timetable.', 'bsa-portal' ); ?></li>
					<li><?php esc_html_e( 'Read school notices and events.', 'bsa-portal' ); ?></li>
					<li><?php esc_html_e( 'View exam results once the school publishes them.', 'bsa-portal' ); ?></li>
				</ul>
				<p class="bsa-home__note"><?php esc_html_e( 'Parents with more than one child at BSA see all their children from one account.', 'bsa-portal' ); ?></p>
			</div>

			<aside class="bsa-panel bsa-home__help" aria-labelledby="bsa-help-title">
				<h2 id="bsa-help-title"><?php esc_html_e( 'Need an account or help?', 'bsa-portal' ); ?></h2>
				<p><?php esc_html_e( 'Accounts are created by the school office. If you have not received your sign-in details, or cannot sign in, contact us.', 'bsa-portal' ); ?></p>
				<dl class="bsa-contact">
					<dt><?php esc_html_e( 'Phone', 'bsa-portal' ); ?></dt>
					<dd>
						<a href="tel:+263772609503">+263 77 260 9503</a><br>
						<a href="tel:+263778070422">+263 77 807 0422</a><br>
						<a href="tel:+263783655488">+263 78 365 5488</a>
					</dd>
					<dt><?php esc_html_e( 'Email', 'bsa-portal' ); ?></dt>
					<dd><a href="mailto:info@bindurascienceacademy.co.zw">info@bindurascienceacademy.co.zw</a></dd>
					<dt><?php esc_html_e( 'Address', 'bsa-portal' ); ?></dt>
					<dd>3159 Aerodrome Medium, Bindura, Mashonaland Central, Zimbabwe</dd>
				</dl>
				<p><a class="bsa-link" href="https://bindurascienceacademy.co.zw/"><?php esc_html_e( 'Visit the school website', 'bsa-portal' ); ?></a></p>
			</aside>
		</div>
	</section>
</div>
<?php
get_footer();
