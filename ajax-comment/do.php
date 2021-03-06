<?php 

function ajax_comment_scripts(){
    wp_enqueue_style( 'ajax-comment', get_template_directory_uri() . '/ajax-comment/ajax-comment.css', array(), '20141010' );
    wp_enqueue_script( 'ajax-comment', get_template_directory_uri() . '/ajax-comment/ajax-comment.js', array( 'jquery' ), '20141010', true );
    wp_localize_script( 'ajax-comment', 'ajaxcomment', array(
        'ajax_url'   => admin_url('admin-ajax.php')
    ) );
}
add_action( 'wp_enqueue_scripts', 'ajax_comment_scripts' );

add_action('wp_ajax_nopriv_ajax_comment', 'ajax_comment_callback');
add_action('wp_ajax_ajax_comment', 'ajax_comment_callback');
function ajax_comment_callback(){
    global $wpdb;
    $comment_post_ID = isset($_POST['comment_post_ID']) ? (int) $_POST['comment_post_ID'] : 0;
    $post = get_post($comment_post_ID);
    $post_author = $post->post_author;
    if ( empty($post->comment_status) ) {
        do_action('comment_id_not_found', $comment_post_ID);
        ajax_comment_err('Invalid comment status.');
    }
    $status = get_post_status($post);
    $status_obj = get_post_status_object($status);
    if ( !comments_open($comment_post_ID) ) {
        do_action('comment_closed', $comment_post_ID);
        ajax_comment_err('Sorry, comments are closed for this item.');
    } elseif ( 'trash' == $status ) {
        do_action('comment_on_trash', $comment_post_ID);
        ajax_comment_err('Invalid comment status.');
    } elseif ( !$status_obj->public && !$status_obj->private ) {
        do_action('comment_on_draft', $comment_post_ID);
        ajax_comment_err('Invalid comment status.');
    } elseif ( post_password_required($comment_post_ID) ) {
        do_action('comment_on_password_protected', $comment_post_ID);
        ajax_comment_err('Password Protected');
    } else {
        do_action('pre_comment_on_post', $comment_post_ID);
    }
    $comment_author       = ( isset($_POST['author']) )  ? trim(strip_tags($_POST['author'])) : null;
    $comment_author_email = ( isset($_POST['email']) )   ? trim($_POST['email']) : null;
    $comment_author_url   = ( isset($_POST['url']) )     ? trim($_POST['url']) : null;
    $comment_content      = ( isset($_POST['comment']) ) ? trim($_POST['comment']) : null;
    $user = wp_get_current_user();
    if ( $user->exists() ) {
        if ( empty( $user->display_name ) )
            $user->display_name=$user->user_login;
        $comment_author       = esc_sql($user->display_name);
        $comment_author_email = esc_sql($user->user_email);
        $comment_author_url   = esc_sql($user->user_url);
        $user_ID              = esc_sql($user->ID);
        if ( current_user_can('unfiltered_html') ) {
            if ( wp_create_nonce('unfiltered-html-comment_' . $comment_post_ID) != $_POST['_wp_unfiltered_html_comment'] ) {
                kses_remove_filters();
                kses_init_filters();
            }
        }
    } else {
        if ( get_option('comment_registration') || 'private' == $status )
            ajax_comment_err('Sorry, you must be logged in to post a comment.');
    }
    $comment_type = '';
    if ( get_option('require_name_email') && !$user->exists() ) {
        if ( 6 > strlen($comment_author_email) || '' == $comment_author )
            ajax_comment_err( 'Error: please fill the required fields (name, email).' );
        elseif ( !is_email($comment_author_email))
            ajax_comment_err( 'Error: please enter a valid email address.' );
    }
    if ( '' == $comment_content )
        ajax_comment_err( 'Error: please type a comment.' );
    $dupe = "SELECT comment_ID FROM $wpdb->comments WHERE comment_post_ID = '$comment_post_ID' AND ( comment_author = '$comment_author' ";
    if ( $comment_author_email ) $dupe .= "OR comment_author_email = '$comment_author_email' ";
    $dupe .= ") AND comment_content = '$comment_content' LIMIT 1";
    if ( $wpdb->get_var($dupe) ) {
        ajax_comment_err('Duplicate comment detected; it looks as though you&#8217;ve already said that!');
    }
    if ( $lasttime = $wpdb->get_var( $wpdb->prepare("SELECT comment_date_gmt FROM $wpdb->comments WHERE comment_author = %s ORDER BY comment_date DESC LIMIT 1", $comment_author) ) ) {
        $time_lastcomment = mysql2date('U', $lasttime, false);
        $time_newcomment  = mysql2date('U', current_time('mysql', 1), false);
        $flood_die = apply_filters('comment_flood_filter', false, $time_lastcomment, $time_newcomment);
        if ( $flood_die ) {
            ajax_comment_err('You are posting comments too quickly.  Slow down.');
        }
    }
    $comment_parent = isset($_POST['comment_parent']) ? absint($_POST['comment_parent']) : 0;
    $commentdata = compact('comment_post_ID', 'comment_author', 'comment_author_email', 'comment_author_url', 'comment_content', 'comment_type', 'comment_parent', 'user_ID');

    $comment_id = wp_new_comment( $commentdata );


    $comment = get_comment($comment_id);
    do_action('set_comment_cookies', $comment, $user);
    $comment_depth = 1;
    $tmp_c = $comment;
    while($tmp_c->comment_parent != 0){
        $comment_depth++;
        $tmp_c = get_comment($tmp_c->comment_parent);
    }
    $GLOBALS['comment'] = $comment;
    //这里修改成你的评论结构
    if(!$comment->comment_parent){ ?>

<li <?php comment_class(); ?> id="li-comment-<?php comment_ID() ?>">

  <article id="comment-<?php comment_ID(); ?>" class="comment-body">

    <header class="comment-header">
      <span class="comment-author"><?php printf( __( '%s says:'), get_comment_author_link() ); ?></span>
    </header>

    <section class="comment-content">
      <?php comment_text(); ?>
    </section>

    <span class="floor flr"><?php printf('%1$s L', ++$commentcount); ?></span>

    <footer class="comment-footer">
      <span class="datetime"><?php comment_date('Y-m-d') ?> <?php comment_time() ?> </span>
    </footer>

  </article>

<?php }else{?>

<li <?php comment_class(); ?> id="li-comment-<?php comment_ID() ?>">

  <article id="comment-<?php comment_ID(); ?>" class="comment-body comment-children-body">
    <header class="comment-header">
      <span class="comment-author"><?php $parent_id = $comment->comment_parent; $comment_parent = get_comment($parent_id); printf('%s', get_comment_author_link()) ?> to <a href="<?php echo "#comment-".$parent_id;?>"><?php echo $comment_parent->comment_author;?></a>: </span>
    </header>

    <section class="comment-content">
      <?php comment_text(); ?>
    </section>

    <span class="floor flr"><?php if( $depth > 1){printf('B%1$s', $depth-1);} ?></span>

    <footer class="comment-footer">
      <span class="datetime"><?php comment_date('Y-m-d') ?> <?php comment_time() ?> </span>
    </footer>

  </article>
    <?php
     } die();
}
function ajax_comment_err($a) {
    header('HTTP/1.0 500 Internal Server Error');
    header('Content-Type: text/plain;charset=UTF-8');
    echo $a;
    exit;
}