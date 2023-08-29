<?php 

namespace JumpGroup\ImageHanding;

use WP_CLI;

class WebpConvert {
  private $exit_on_error = false;

  public function convert($args, $option) {
    if(!is_plugin_active( 'performance-lab/load.php')) {
      WP_CLI::error("Performance Plugin is not active, please activate it", $this->exit_on_error);
      exit();
    }

    include_once ABSPATH . 'wp-admin/includes/image-edit.php';
    
    $post_in = isset($option['post-in']) ? explode(',', $option['post-in']) : [];
    $dry_run = isset($option['dry-run']) ? true : false;
    $this->exit_on_error = isset($option['exit-on-error']) ? true : false;
    $bucker = isset($option['bucket']) ? 's3://jumpgroup-'.$options['bucket'].'/'.$options['bucket'].'/uploads' : false;
  
    $posts = get_posts([
      'post_type' => 'attachment',
      'post_mime_type' => ['image/jpeg', 'image/jpg'],
      'posts_per_page' => -1,
      'post__in' => $post_in
    ]);

    if (empty($posts)) {
      WP_CLI::line("NO ATTACHMENTS FOUND");
      exit();
    }

    WP_CLI::line("FOUND ATTACHMENTS: ".count($posts));
    WP_CLI::line(WP_CLI\Utils\format_items('table', $posts, ['ID', 'post_title', 'post_mime_type', 'guid']));

    $_REQUEST['history'] = '[{"r":90},{"r":-90}]';
    $_REQUEST['target'] = 'all';
    $_REQUEST['context'] = 'edit-attachment';
    $_REQUEST['do'] = 'save';
    
    foreach ($posts as $key => $post) {
      WP_CLI::line("LOADING ATTACHMENT: ".$post->ID . ' - ' . $post->post_title);
      $_REQUEST['postid'] = $post->ID;

      $wp_get_attachment_metadata = wp_get_attachment_metadata($post->ID);
      $sizes = $wp_get_attachment_metadata['sizes'] ?? [];
      $sources = $wp_get_attachment_metadata['sources'] ?? [];
      $check_sizes = [];
      $check_sources = [];
      $original_attached_file = get_attached_file($post->ID);
      $old_attached_file = "";
      $post->original_attached_file = "";
      $post->old_attached_file = "";
      $post->new_attached_file = "";
      $post->webp_full = "";
      $post->webp_medium = "";
      $post->webp_large = "";
      $post->webp_thumbnail = "";
      $post->webp_medium_large = "";

      if (!empty($sizes)) {
        $check_sizes = array_filter($sizes, function ($size) {
          // TODO: Forse è meglio controllare se esiste il file o almeno vedere se il file nelle sostanze è webp
          return $size['mime-type'] === 'image/webp';
        });
      }

      if (!empty($sources)) {
        $check_sources = array_filter($sources, function ($source) {
          return !empty($source['image/webp']);
        });
      }


      $sizes_diff = array_diff(array_keys($sizes), array_keys($check_sizes));

      if (!empty($check_sizes) && empty($sizes_diff)) {
        WP_CLI::warning("ATTACHMENT ALREADY CONVERTED!");
        $post->webp_full = $sources['image/webp']['file'] ?? "";
        $post->webp_medium = $check_sizes['medium']['file'] ?? "";
        $post->webp_large = $check_sizes['large']['file'] ?? "";
        $post->webp_thumbnail = $check_sizes['thumbnail']['file'] ?? "";
        $post->webp_medium_large = $check_sizes['medium_large']['file'] ?? "";

        continue;
      } 

      if (!empty($check_sizes) && !empty($sizes_diff)) {
        WP_CLI::warning("ATTACHMENT PARTIALLY CONVERTED! Not converted sizes: ". implode(', ', array_diff(array_keys($sizes), array_keys($check_sizes))));
        $post->webp_full = $sources['image/webp']['file'] ?? "";
        $post->webp_medium = $check_sizes['medium']['file'] ?? "";
        $post->webp_large = $check_sizes['large']['file'] ?? "";
        $post->webp_thumbnail = $check_sizes['thumbnail']['file'] ?? "";
        $post->webp_medium_large = $check_sizes['medium_large']['file'] ?? "";

        continue;
      }

      WP_CLI::line("STARTING CONVERSION");
      $wp_save_image = wp_save_image($post->ID);
      $old_attached_file = get_attached_file($post->ID);
      WP_CLI::line("OLD ATTACHED FILE: ".$old_attached_file);

      if (is_wp_error($wp_save_image) || !empty($wp_save_image->error)) {
        WP_CLI::error($wp_save_image->msg ?? $wp_save_image->error, $exit_on_error);
        continue;
      }

      WP_CLI::success($wp_save_image->msg);
      
      $ext = pathinfo($old_attached_file, PATHINFO_EXTENSION);
      WP_CLI::line("OLD EXTENSION: ".$ext);
      
      if (empty($ext) || !($ext === 'jpg' || $ext === 'jpeg')) {
        WP_CLI::error("ERRORE ESTENSIONE FILE", $exit_on_error);
        continue;
      }
      
      $new_attached_file = str_replace('.'.$ext, '.webp', $old_attached_file);
      WP_CLI::line("NEW ATTACHED FILE: ".$new_attached_file);
      
      WP_CLI::line("UPDATING METADATA");
      $update_attached_file = update_attached_file($post->ID, $new_attached_file);
      $updateted_file_ext = pathinfo($new_attached_file, PATHINFO_EXTENSION);

      WP_CLI::line("UPDATED ATTACHED FILE: ".$update_attached_file);
      WP_CLI::line("UPDATED ATTACHED FILE EXTENSION: ".$updateted_file_ext);
      

      if (empty($update_attached_file)) {
        WP_CLI::error('ERRORE UPDATE ATTACHED FILE', $exit_on_error);
        continue;
      } elseif ($updateted_file_ext !== 'webp') {
        WP_CLI::error('ESTENSIONE NON CORRETTA: '.$updateted_file_ext , $exit_on_error);
        continue;
      } else {
        WP_CLI::success("UPDATED ATTACHED FILE");
      }

      // map info on post object
      $post->original_attached_file = $original_attached_file;
      $post->old_attached_file = $old_attached_file;
      $post->new_attached_file = $new_attached_file;
      $post->webp_full = $sources['image/webp']['file'] ?? "";
      $post->webp_medium = $sizes['medium']['file'] ?? "";
      $post->webp_large = $sizes['large']['file'] ?? "";
      $post->webp_thumbnail = $sizes['thumbnail']['file'] ?? "";
      $post->webp_medium_large = $sizes['medium_large']['file'] ?? "";
      
      $wp_save_image = wp_save_image($post->ID);
      WP_CLI::line("END ATTACHMENT CONVERSION");
    }
    
    WP_CLI::success("END CONVERSION");
    // recap
    WP_CLI::line(WP_CLI\Utils\format_items('table', $posts, ['ID', 'guid', 'webp_full', 'webp_medium', 'webp_large', 'webp_thumbnail', 'webp_medium_large']));

    exit();
  }

  public function clear_old ($args, $options) {
    if(!is_plugin_active( 'performance-lab/load.php')) {
      WP_CLI::error("Performance Plugin is not active, please activate it", $this->exit_on_error);
      exit();
    }

    $post_in = isset($options['post-in']) ? explode(',', $options['post-in']) : [];
    $year_month = isset($options['year-month']) ? $options['year-month'] : null;
    $this->exit_on_error = isset($options['exit-on-error']) ? true : false;
    $bucket = isset($options['bucket']) ? 's3://jumpgroup-'.$options['bucket'].'/'.$options['bucket'].'/uploads' : false;

    if (empty($year_month) || !preg_match('/^\d{4}\/\d{2}$/', $year_month)) {
      WP_CLI::error("YEAR MONTH NOT VALID or NOT SET (i.e: YYYY/MM)", $this->exit_on_error);
      exit();
    }

    if(empty($bucket)) {
      WP_CLI::error("BUCKET NOT SET", $this->exit_on_error);
      exit();
    }

    $file_base_path = $bucket.'/'.$year_month.'/';

    $posts = get_posts([
      'post_type' => 'attachment',
      'post_mime_type' => ['image/jpeg', 'image/jpg'],
      'posts_per_page' => -1,
      'post__in' => $post_in
    ]);

    if (empty($posts)) {
      WP_CLI::line("NO ATTACHMENTS FOUND");
      exit();
    }

    WP_CLI::line("FOUND ATTACHMENTS: ".count($posts));
    WP_CLI::line(WP_CLI\Utils\format_items('table', $posts, ['ID', 'post_title', 'post_mime_type', 'guid']));

    foreach ($posts as $key => $post) {
      $wp_attached_file = get_attached_file($post->ID);
      $wp_get_attachment_metadata = wp_get_attachment_metadata($post->ID);
      $wp_get_attachment_backup_sizes = get_post_meta($post->ID, '_wp_attachment_backup_sizes', true);
      $wp_get_attachment_backup_sources = get_post_meta($post->ID, '_wp_attachment_backup_sources', true);

      // write a snippet of code that get the edit value from $wp_attached_file string using the metch string '-e[0-9]{13}' and save it in $current_attached_edit_value
      $current_attached_edit_value = preg_match('/-e[0-9]{13}/', $wp_attached_file, $matches);
      if (empty($current_attached_edit_value)) {
        WP_CLI::line("NO EDIT VALUE FOUND");
        continue;
      }

      $current_attached_edit_value = str_replace('-e', '', $matches[0]);
      // filter $wp_get_attachement_backup_sizes array to get only the items that doesn't contains the edit value in the key
      $wp_get_attachment_old_backup_sizes = array_filter($wp_get_attachment_backup_sizes, function($key) use ($current_attached_edit_value) {
        return strpos($key, $current_attached_edit_value) === false;
      }, ARRAY_FILTER_USE_KEY);

      if(!empty($wp_get_attachment_old_backup_sizes)) {
        $path_to_delete = [];
        foreach ($wp_get_attachment_old_backup_sizes as $key => $value) {
          [$crop, $edit] = explode('-', $key);
          if (!empty($edit) && $edit !== $current_attached_edit_value && $edit !== 'orig') {
            $file_path = $file_base_path.$value['file'];

            if (file_exists($file_path)) {
              $unlink_res = unlink($file_path);

              if ($unlink_res) {
                WP_CLI::line("FILE DELETED: ".$file_path);
                unset($wp_get_attachment_backup_sizes[$key]);
                unset($wp_get_attachment_backup_sources[$key]);
              } else {
                WP_CLI::error("ERROR DELETING FILE: ".$file_path, $this->exit_on_error);
              }
            }
          }
        }

        update_post_meta($post->ID, '_wp_attachment_backup_sizes', $wp_get_attachment_backup_sizes);
        update_post_meta($post->ID, '_wp_attachment_backup_sources', $wp_get_attachment_backup_sources);
      }
    }
  }
}