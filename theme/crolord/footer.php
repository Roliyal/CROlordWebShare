<?php if (!defined('ABSPATH')) exit;
if (fieldnotes_show_site_footer()) :
?>
<footer class="site-footer home-colophon">
    <div class="colophon-quiet-inner">
        <p class="colophon-theme"><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer">蜀ICP备2022020810号-1</a></p>
        <p class="colophon-copyright">&copy; <?php echo esc_html(wp_date('Y')); ?> issac.</p>
    </div>
</footer>
<?php endif; wp_footer(); ?>
</body>
</html>
