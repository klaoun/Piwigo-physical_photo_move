<?php
// +-----------------------------------------------------------------------+
// | Piwigo - a PHP based picture gallery                                  |
// +-----------------------------------------------------------------------+
// | Copyright(C) 2008-2017 Piwigo Team                  http://piwigo.org |
// | Copyright(C) 2003-2008 PhpWebGallery Team    http://phpwebgallery.net |
// | Copyright(C) 2002-2003 Pierrick LE GALL   http://le-gall.net/pierrick |
// +-----------------------------------------------------------------------+
// | This program is free software; you can redistribute it and/or modify  |
// | it under the terms of the GNU General Public License as published by  |
// | the Free Software Foundation                                          |
// |                                                                       |
// | This program is distributed in the hope that it will be useful, but   |
// | WITHOUT ANY WARRANTY; without even the implied warranty of            |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU      |
// | General Public License for more details.                              |
// |                                                                       |
// | You should have received a copy of the GNU General Public License     |
// | along with this program; if not, write to the Free Software           |
// | Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307, |
// | USA.                                                                  |
// +-----------------------------------------------------------------------+

if( !defined("PHPWG_ROOT_PATH") )
{
  die ("Hacking attempt!");
}

include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
include_once(PHPWG_ROOT_PATH.'admin/include/tabsheet.class.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

check_status(ACCESS_ADMINISTRATOR);

// +-----------------------------------------------------------------------+
// | Basic checks                                                          |
// +-----------------------------------------------------------------------+

$_GET['image_id'] = $_GET['tab'];

check_input_parameter('image_id', $_GET, false, PATTERN_ID);

$admin_photo_base_url = get_root_url().'admin.php?page=photo-'.$_GET['image_id'];

// +-----------------------------------------------------------------------+
// | Process form                                                          |
// +-----------------------------------------------------------------------+

if (isset($_POST['move_photo']))
{

  // was simulation (test mode) checked?
  if (isset($_POST['test_mode']) and $_POST['test_mode'] == 1)
  {
    $ppm_test_mode = true;
    // debugging messages (for simulation)
    array_push(
      $page['warnings'],
      l10n('MSG_TEST_MODE_ON')
      );
  }
  else
  {
    $ppm_test_mode = false;
  }

  // check selected target category and act accordingly
  if (isset($_POST['cat_id']))
  {
    $target_cat = $_POST['cat_id'];

    // retrieve information about current photo
    $image_info = get_image_infos($_GET['image_id']);
    $storage_cat_id = $image_info['storage_category_id'];
    $source_file_path = $image_info['path'];
    $source_cat_name = get_cat_info($storage_cat_id)['name'];
    $source_dir = pathinfo($source_file_path)['dirname'];
    $source_file_name = pathinfo($source_file_path)['filename'];
    $source_file_ext = pathinfo($source_file_path)['extension'];

    // retrieve representative info, if applicable
    $source_rep_ext = $image_info['representative_ext'];
    if (!is_null($source_rep_ext))
    {
      $source_rep_path = original_to_representative($source_file_path, $source_rep_ext);
    }
    else
    {
      $source_rep_path = '';
    }

    // no move necessary (same category selected)
    if ($target_cat == $storage_cat_id)
    {
      array_push(
        $page['messages'],
        l10n('MSG_NO_WORK')
        );
    }
    else 
    {
      // get destination category information
      $dest_cat_info = get_cat_info($target_cat);
      $dest_cat_name = $dest_cat_info['name'];
      $dest_uppercats = explode(',',  $dest_cat_info['uppercats']);
      $dest_cat_path = get_fulldirs($dest_uppercats);
      $dest_cat_path = $dest_cat_path[$target_cat];
      $dest_file_exists = false;
      $dest_rep_ext = '';
      $dest_rep_path = '';
     
      // check to see if filename already exists in destination
      if (file_exists($dest_cat_path.'/'.$source_file_name.'.'.$source_file_ext))
      {
        $dest_file_exists = true;
        // append date-time to filename to avoid overwriting existing file
        list($dbnow) = pwg_db_fetch_row(pwg_query('SELECT NOW();'));
        $date_string = preg_replace('/[^\d]/', '', $dbnow);
        $dest_file_name = $source_file_name.'-'.$date_string.'.'.$source_file_ext;

        // build rename message
        $rename_msg = l10n('MESSAGE_RENAME_1').$source_file_name.'.'.$source_file_ext.
          l10n('MSG_RENAME_2').$dest_file_name.l10n('MSG_RENAME_3');

        array_push(
          $page['messages'],
            sprintf($rename_msg)
          );
      }
      else
      {
        $dest_file_name = $source_file_name.'.'.$source_file_ext;
      }

      // build the new destination path and filename
      $dest_file_path = $dest_cat_path.'/'.$dest_file_name;
    
      // move the file
      $move_status_ok = true;
      if (!$ppm_test_mode)
      {
        $move_status_ok = rename($source_file_path, $dest_file_path);
        @chmod($dest_file_path, 0644);
      }

      if ($move_status_ok)
      {
        // make the database changes associated with the move 
        if (!$ppm_test_mode)
        {
          // update file, path and storage category on the image table
          single_update(
            IMAGES_TABLE,
            array(
              'file' => $dest_file_name,
              'path' => $dest_file_path,
              'storage_category_id' => $target_cat,
              ),
            array('id' => $_GET['image_id'])
            );

          // check for an existing row for the target category (the image is virtually linked
          // to the physical destination album already)
          $query = '
          SELECT
            COUNT(*) as count
            FROM '.IMAGE_CATEGORY_TABLE.'
            WHERE image_id = '. $_GET['image_id'].'
            AND category_id = '.$target_cat.'
            ;';
          $result = pwg_query($query);
          $row = pwg_db_fetch_assoc($result);
          $count = $row['count'];

          // show a message indicating a virtual link has been replaced
          if ($count == 1)
          {
            array_push(
              $page['messages'],
              l10n('MSG_LINK_REMOVED')
              );
          }

          // delete the existing virtual link
          $query = '
          DELETE
            FROM '.IMAGE_CATEGORY_TABLE.'
            WHERE image_id = '. $_GET['image_id'].'
            AND category_id = '.$target_cat.'
            ;';
          pwg_query($query);

          // update category on the image category table
          single_update(
            IMAGE_CATEGORY_TABLE,
            array(
              'category_id' => $target_cat,
              ),
            array(
              'image_id' => $_GET['image_id'],
              'category_id' => $storage_cat_id,
              )
            );

          $move_status_ok = true;
          $dest_rep_ext = $image_info['representative_ext'];

          // move representative (and representative derivatives)
          if (!is_null($dest_rep_ext))
          {
            $dest_rep_path = original_to_representative($dest_file_path, $dest_rep_ext);

            // create the pwg_representative folder if it doesn't exist in the destination
            if (!is_dir($dest_cat_path.'/pwg_representative'))
            {
              mkdir($dest_cat_path.'/pwg_representative');
            }

            // move representative
            $move_status_ok = rename($source_rep_path, $dest_rep_path);
            @chmod($dest_rep_path, 0644);

            // remove the source pwg_representative directory if it's empty
            @rmdir($source_dir.'/pwg_representative');

            // move derivatives (thumbnails, resizes, etc.) of the representative
            $source_derivatives = './'.PWG_DERIVATIVE_DIR.$source_dir.'/pwg_representative/'.$source_file_name.'-*.'.$dest_rep_ext;
            $dest_derivatives = './'.PWG_DERIVATIVE_DIR.$dest_cat_path.'/pwg_representative/';

            // create the pwg_representative folder if it doesn't exist in the destination
            if (!is_dir($dest_derivatives))
            {
              mkdir($dest_derivatives);
              // also copy the index.htm file to the new directory
              copy('./'.PWG_DERIVATIVE_DIR.$source_dir.'/pwg_representative/index.htm', $dest_derivatives.'/index.htm');
            }

            // loop through the list of derivatives and move them to the destination
            foreach (glob($source_derivatives) as $source_derivative_filename)
            {
              $dest_derivative_filename = pathinfo($source_derivative_filename)['basename'];
              $move_status_ok = rename($source_derivative_filename, $dest_derivatives.'/'.$dest_derivative_filename);
              @chmod($dest_derivatives.'/'.$dest_derivative_filename, 0644);
            }

            // count the files left in the source derivatives folder
            $remaining_file_count = count(scandir(PWG_DERIVATIVE_DIR.$source_dir.'/pwg_representative/')) - 2;
            if ($remaining_file_count == 1) 
            {
              // if there's only one file left in the directory, it should be index.htm; remove it.
              @unlink(PWG_DERIVATIVE_DIR.$source_dir.'/pwg_representative/index.htm');
            }

            // now remove the source pwg_representative directory if it's empty
            @rmdir(PWG_DERIVATIVE_DIR.$source_dir.'/pwg_representative/');
          }
          else
          {
            // move derivatives (thumbnails, resizes, etc.)
            $source_derivatives = './'.PWG_DERIVATIVE_DIR.$source_dir.'/'.$source_file_name.'-*.'.$source_file_ext;
            $dest_derivatives = './'.PWG_DERIVATIVE_DIR.$dest_cat_path;

            // loop through the list of derivatives and move them to the destination
            foreach (glob($source_derivatives) as $source_derivative_filename)
            {
              $dest_derivative_filename = pathinfo($source_derivative_filename)['basename'];
              $move_status_ok = rename($source_derivative_filename, $dest_derivatives.'/'.$dest_derivative_filename);
              @chmod($dest_derivatives.'/'.$dest_derivative_filename, 0644);
            }
          }

          if ($move_status_ok)
          {
            // everything successfully moved to destination
            $success_msg = l10n('MSG_SUCCESS').$dest_cat_name.'.';
            array_push(
              $page['infos'],
              sprintf($success_msg)
              );
          }
          else
          {
            array_push(
              $page['errors'],
              l10n('MSG_REP_MOVE_ERR')
              );
          }

        } // end check for test mode
      }
      else // an error occurred during the file move
      {
        array_push(
          $page['errors'],
          l10n('MSG_FILE_MOVE_ERR')
          );
      }
    
      // debugging messages (for simulation)
      if ($ppm_test_mode)
      {
        // build debug strings
        $debug_line_1 = l10n('DBG_SRC_ALBUM').$source_cat_name.' (id: '.$storage_cat_id.')';
        $debug_line_2 = l10n('DBG_SRC_FILE').$source_file_name.'.'.$source_file_ext;
        $debug_line_3 = l10n('DBG_SRC_DIR').$source_dir;
        $debug_line_4 = l10n('DBG_SRC_PATH').$source_file_path;
        $debug_line_5 = l10n('DBG_DEST_ALBUM').$dest_cat_name.' (id: '.$target_cat.')';
        $debug_line_6 = l10n('DBG_DEST_FILE').$dest_file_name;
        $debug_line_7 = l10n('DBG_DEST_DIR').$dest_cat_path;
        $debug_line_8 = l10n('DBG_DEST_FILE').$dest_file_path;
        array_push(
          $page['messages'],
          sprintf($debug_line_1),
          sprintf($debug_line_2),
          sprintf($debug_line_3),
          sprintf($debug_line_4),
          sprintf($debug_line_5),
          sprintf($debug_line_6),
          sprintf($debug_line_7),
          sprintf($debug_line_8)
          );
      }
    } // move file
  }
  else // no destination selected
  {
    array_push(
      $page['messages'],
      l10n('MSG_NO_DEST')
      );
  }

} // end post

// +-----------------------------------------------------------------------+
// | Tabs                                                                  |
// +-----------------------------------------------------------------------+

include_once(PHPWG_ROOT_PATH.'admin/include/tabsheet.class.php');

$page['tab'] = 'ppm';

$tabsheet = new tabsheet();
$tabsheet->set_id('photo');
$tabsheet->select('ppm');
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames(
  array(
    'plugin_admin_content' => dirname(__FILE__).'/admin.tpl'
    )
  );

// retrieve the id of the storage category of the current photo
$image_info = get_image_infos($_GET['image_id']);
$storage_cat_id = $image_info['storage_category_id'];

// retrieve the name of the storage category of the photo
$storage_cat_info = get_cat_info($storage_cat_id);

// populate target category scroll with physical albums only
require(PPM_PATH.'include/ppm_dest_album_list.inc.php');

$template->assign(
  array(
    'TITLE' => render_element_name($image_info),
    'TN_SRC' => DerivativeImage::thumb_url($image_info),
    'current_path' => $image_info['path'],
    'storage_category' => $storage_cat_info['name'],
    )
  );

// +-----------------------------------------------------------------------+
// | sending html code                                                     |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
?>
