<?php
/*
Plugin Name: MD5 Hasher
Plugin URI: http://www.jclabs.co.uk
Description: Security Process to show latest modified/created files, run as frequently as deemed fit, an email is sent automatically with the results.	
Author: James Collings
Version: 0.0.1
Author URI: http://www.jclabs.co.uk
*/

define('MD5_HASHER_DIR', plugin_dir_path( __FILE__ ));

class Md5_Hasher{

	private $dir = ABSPATH;
	private $file_check = 'md5_checksums.txt';
	private $file_change = 'md5_changes.txt';
	private $compare = true;
	
	public function __construct(){
		add_action('Md5_Checksum', array($this, 'run_hash_check'));
		add_action('wp', array($this, '_activate'));
	}

	public function _activate() {
	    if ( !wp_next_scheduled( 'Md5_Checksum' ) ) {
	        wp_schedule_event( time(), 'hourly', 'Md5_Checksum');
	    }
	}

	public function run_hash_check() {

		$md5_gen_old = array();
		$md5_gen_output = array();
        $md5_changed_output = array();

		if(!is_file(MD5_HASHER_DIR . $this->file_check)){
			$this->compare = false;
			$fh = fopen(MD5_HASHER_DIR . $this->file_check, 'x');
			
		}else{
			$fh = fopen(MD5_HASHER_DIR . $this->file_check, 'r');
			$md5_gen_old = (Array)json_decode(fread($fh, filesize(MD5_HASHER_DIR.$this->file_check)));
		}
        
        fclose($fh);

        $rdi = new RecursiveDirectoryIterator($this->dir);
        $rii = new RecursiveIteratorIterator($rdi);
        foreach($rii as $name => $obj){
            $dir_file = $obj->getRealPath(); 
            $md5_gen_output[$dir_file] = array(
                'md5' => md5_file($dir_file),
                'filename' => $obj->getFilename(),
                'real_path' => $dir_file
            );

            if(!isset($md5_gen_old[$dir_file]->md5)){
                // new file
                $md5_changed_output[$dir_file] = array(
                    'md5' => md5_file($dir_file),
                    'filename' => $obj->getFilename(),
                    'real_path' => $dir_file,
                    'modified' => 'new'
                );
            }else if($md5_gen_old[$dir_file]->md5 !== $md5_gen_output[$dir_file]['md5']){
                // modified file
                $md5_changed_output[$dir_file] = array(
                    'md5' => md5_file($dir_file),
                    'filename' => $obj->getFilename(),
                    'real_path' => $dir_file,
                    'modified' => 'edited'
                ); 
            }
        }

        // save new md5 hashes
        $fh = fopen(MD5_HASHER_DIR.$this->file_check, 'w');
        fwrite($fh, json_encode($md5_gen_output));
        fclose($fh);

        // save changed file_list
        if(is_file(MD5_HASHER_DIR . $this->file_change)){
	        $fh = fopen(MD5_HASHER_DIR.$this->file_change, 'a');
	        fwrite($fh, date('d/m/Y h:i:s').":\n\n");
	        foreach($md5_changed_output as $k => $v){
	            fwrite($fh, $v['filename'].' => '.$v['modified']. "\n");
	        }
	        fwrite($fh, "\n");
        }else{
        	$fh = fopen(MD5_HASHER_DIR.$this->file_change, 'x');
        }
        fclose($fh); 
	    
        $this->emailChanges($md5_changed_output);
	}

    private function emailChanges(){
        $message = 'File Changes:'."\n";
        foreach($md5_changed_output as $k => $v){
            $message .=  $v['filename'].' => '.$v['modified']. "\n";
        }
        
        $emails = $this->getAdminEmails();
        if($emails){
            wp_mail( $emails, 'Website Changes', $message);
        }else{
            wp_mail( get_bloginfo('admin_email'), 'Website Changes', $message);
        }
    }

    /**
     * Get list of administrator email addresses
     * @param  array $ids specific admin ids
     * @return array      admin email addresses
     */
    private function getAdminEmails($ids = null){
        $emails = array();
        $args = array('role' => 'Administrator');

        if(is_array($ids) && !empty($ids)){
            $args['include'] = $ids;
        }

        $wp_user_query = new WP_User_Query($args);
        $admins = $wp_user_query->get_results();

        if(!empty($admins)){
            foreach($admins as $admin){
                $emails[] = $admin->user_email;
            }
        }

        if(empty($emails))
            return false;

        return $emails;
    }
}

$MD5_Hasher = new MD5_Hasher();