<?php
/**
* Template Name: Quack Detail Page
*
* @package MonsTec
* @subpackage Produck
* @since Produck 1.0
*/
// prevent direct access
defined('ABSPATH') or die('Quidquid agis, prudenter agas et respice finem!');

get_header();
?>

<div id="primary">
	<main id="main" class="site-main" role="main">
		<?php
		// Start the loop.
		while (have_posts()) :
			the_post();
		?>
			<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
				<header class="quacks-header">
					<?php the_title( '<h1 class="quacks-h1">', '</h1>' ); ?>
				</header><!-- .entry-header -->
					<?php
					the_content();
					?>
			</article><!-- #post-## -->
		<?php
		// End of the loop.
		endwhile;
		?>
	</main><!-- .site-main -->
</div><!-- .content-area -->

<?php get_footer(); ?>