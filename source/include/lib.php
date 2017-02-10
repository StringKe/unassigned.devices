<?php
/* Copyright 2015, Guilherme Jardim
 * Copyright 2016-2017, Dan Landon
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

$plugin = "unassigned.devices";
// $VERBOSE=TRUE;

$paths =  array("smb_extra"       => "/boot/config/smb-extra.conf",
				"smb_usb_shares"  => "/etc/samba/unassigned-shares",
				"usb_mountpoint"  => "/mnt/disks",
				"device_log"      => "/var/log/",
				"log"             => "/var/log/{$plugin}.log",
				"config_file"     => "/boot/config/plugins/{$plugin}/{$plugin}.cfg",
				"state"           => "/var/state/${plugin}/${plugin}.ini",
				"hdd_temp"        => "/var/state/${plugin}/hdd_temp.json",
				"samba_mount"     => "/boot/config/plugins/${plugin}/samba_mount.cfg",
				"iso_mount"       => "/boot/config/plugins/${plugin}/iso_mount.cfg",
				"reload"          => "/var/state/${plugin}/reload.state",
				"unmounting"      => "/var/state/${plugin}/unmounting_%s.state",
				"mounting"        => "/var/state/${plugin}/mounting_%s.state",
				);

$docroot = $docroot ?: @$_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';

if (! is_dir(dirname($paths["state"])) ) {
	@mkdir(dirname($paths["state"]),0777,TRUE);
}

if (! isset($var)){
	if (! is_file("$docroot/state/var.ini")) shell_exec("/usr/bin/wget -qO /dev/null localhost:$(ss -napt|/bin/grep emhttp|/bin/grep -Po ':\K\d+') >/dev/null");
	$var = @parse_ini_file("$docroot/state/var.ini");
}

if (! isset($users)){
	$users = @parse_ini_file("$docroot/state/users.ini", true);
}

if (! isset($disks)){
	$disks = @parse_ini_file("$docroot/state/disks.ini", true);
}

if ( is_file( "plugins/preclear.disk/assets/lib.php" ) )
{
	require_once( "plugins/preclear.disk/assets/lib.php" );
	$Preclear = new Preclear;
}
else
{
	$Preclear = null;
}

#########################################################
#############        MISC FUNCTIONS        ##############
#########################################################

function _echo($m) { echo "<pre>".print_r($m,TRUE)."</pre>";}; 

function save_ini_file($file, $array) {
	$res = array();
	foreach($array as $key => $val) {
		if(is_array($val)) {
			$res[] = PHP_EOL."[$key]";
			foreach($val as $skey => $sval) $res[] = "$skey = ".(is_numeric($sval) ? $sval : '"'.$sval.'"');
		} else {
			$res[] = "$key = ".(is_numeric($val) ? $val : '"'.$val.'"');
		}
	}
	file_put_contents($file, implode(PHP_EOL, $res));
}

function unassigned_log($m, $type = "NOTICE") {
	if ($type == "DEBUG" && ! $GLOBALS["VERBOSE"]) return NULL;
	$m = date("M d H:i:s")." ".print_r($m,true)."\n";
	file_put_contents($GLOBALS["paths"]["log"], $m, FILE_APPEND);
}

function listDir($root) {
	$iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, 
			RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST,
			RecursiveIteratorIterator::CATCH_GET_CHILD);
	$paths = array();
	foreach ($iter as $path => $fileinfo) {
		if (! $fileinfo->isDir()) $paths[] = $path;
	}
	return $paths;
}

function safe_name($string) {
	$string = stripcslashes($string);
	$string = str_replace(array( "(", ")" ), "", $string);
	$string = htmlentities($string, ENT_QUOTES, 'UTF-8');
	$string = preg_replace('~&([a-z]{1,2})(acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml);~i', '$1', $string);
	$string = html_entity_decode($string, ENT_QUOTES, 'UTF-8');
	$string = preg_replace('~[^0-9a-z -_]~i', '', $string);
	$string = preg_replace('~[-_]~i', ' ', $string);
	return trim($string);
}

function exist_in_file($file, $val) {
	return (preg_grep("%${val}%", @file($file))) ? TRUE : FALSE;
}

function is_disk_running($dev) {
	$state = trim(shell_exec("/usr/sbin/hdparm -C $dev 2>/dev/null| /bin/grep -c standby"));
	return ($state == 0) ? TRUE : FALSE;
}

function lsof($dir) {
	return intval(trim(shell_exec("/usr/bin/lsof '{$dir}' 2>/dev/null|/bin/grep -c -v COMMAND")));
}

function get_temp($dev) {
	$tc = $GLOBALS["paths"]["hdd_temp"];
	$temps = is_file($tc) ? json_decode(file_get_contents($tc),TRUE) : array();
	if (isset($temps[$dev]) && (time() - $temps[$dev]['timestamp']) < 120 ) {
		return $temps[$dev]['temp'];
	} else if (is_disk_running($dev)) {
		$temp = trim(shell_exec("/usr/sbin/smartctl -A -d sat,12 $dev 2>/dev/null| /bin/grep -m 1 -i Temperature_Celsius | /bin/awk '{print $10}'"));
		if (! is_numeric($temp)) {
			$temp = trim(shell_exec("/usr/sbin/smartctl -A -d sat,12 $dev 2>/dev/null| /bin/grep -m 1 -i Airflow_Temperature | /bin/awk '{print $10}'"));
		}
		$temp = (is_numeric($temp)) ? $temp : "*";
		$temps[$dev] = array('timestamp' => time(),
							 'temp'      => $temp);
		file_put_contents($tc, json_encode($temps));
		return $temp;
	} else {
		return "*";
	}
}

function verify_precleared($dev) {
	$cleared        = TRUE;
	$disk_blocks    = intval(trim(shell_exec("/sbin/blockdev --getsz $dev  | /bin/awk '{ print $1 }'")));
	$max_mbr_blocks = hexdec("0xFFFFFFFF");
	$over_mbr_size  = ( $disk_blocks >= $max_mbr_blocks ) ? TRUE : FALSE;
	$pattern        = $over_mbr_size ? array("00000", "00000", "00002", "00000", "00000", "00255", "00255", "00255") : 
									   array("00000", "00000", "00000", "00000", "00000", "00000", "00000", "00000");

	$b["mbr1"] = trim(shell_exec("/usr/bin/dd bs=446 count=1 if=$dev 2>/dev/null        |sum|/bin/awk '{print $1}'"));
	$b["mbr2"] = trim(shell_exec("/usr/bin/dd bs=1 count=48 skip=462 if=$dev 2>/dev/null|sum|/bin/awk '{print $1}'"));
	$b["mbr3"] = trim(shell_exec("/usr/bin/dd bs=1 count=1  skip=450 if=$dev 2>/dev/null|sum|/bin/awk '{print $1}'"));
	$b["mbr4"] = trim(shell_exec("/usr/bin/dd bs=1 count=1  skip=511 if=$dev 2>/dev/null|sum|/bin/awk '{print $1}'"));
	$b["mbr5"] = trim(shell_exec("/usr/bin/dd bs=1 count=1  skip=510 if=$dev 2>/dev/null|sum|/bin/awk '{print $1}'"));

	foreach (range(0,15) as $n) {
		$b["byte{$n}"] = trim(shell_exec("/usr/bin/dd bs=1 count=1 skip=".(446+$n)." if=$dev 2>/dev/null|sum|/bin/awk '{print $1}'"));
		$b["byte{$n}h"] = sprintf("%02x",$b["byte{$n}"]);
	}

	unassigned_log("Verifying '$dev' for preclear signature.", "DEBUG");

	if ( $b["mbr1"] != "00000" || $b["mbr2"] != "00000" || $b["mbr3"] != "00000" || $b["mbr4"] != "00170" || $b["mbr5"] != "00085" ) {
		unassigned_log("Failed test 1: MBR signature not valid.", "DEBUG"); 
		$cleared = FALSE;
	}
	# verify signature
	foreach ($pattern as $key => $value) {
		if ($b["byte{$key}"] != $value) {
			unassigned_log("Failed test 2: signature pattern $key ['$value'] != '".$b["byte{$key}"]."'", "DEBUG");
			$cleared = FALSE;
		}
	}
	$sc = hexdec("0x{$b[byte11h]}{$b[byte10h]}{$b[byte9h]}{$b[byte8h]}");
	$sl = hexdec("0x{$b[byte15h]}{$b[byte14h]}{$b[byte13h]}{$b[byte12h]}");
	switch ($sc) {
		case 63:
		case 64:
			$partition_size = $disk_blocks - $sc;
			break;
		case 1:
			if ( ! $over_mbr_size) {
				unassigned_log("Failed test 3: start sector ($sc) is invalid.", "DEBUG");
				$cleared = FALSE;
			}
			$partition_size = $max_mbr_blocks;
			break;
		default:
			unassigned_log("Failed test 4: start sector ($sc) is invalid.", "DEBUG");
			$cleared = FALSE;
			break;
	}
	if ( $partition_size != $sl ) {
		unassigned_log("Failed test 5: disk size doesn't match.", "DEBUG");
		$cleared = FALSE;
	}
	return $cleared;
}

function get_format_cmd($dev, $fs) {
	switch ($fs) {
		case 'xfs':
			return "/sbin/mkfs.xfs -f {$dev}";
			break;
		case 'ntfs':
			return "/sbin/mkfs.ntfs -Q {$dev}";
			break;
		case 'btrfs':
			return "/sbin/mkfs.btrfs -f {$dev}";
			break;
		case 'ext4':
			return "/sbin/mkfs.ext4 -F {$dev}";
			break;
		case 'exfat':
			return "/usr/sbin/mkfs.exfat {$dev}";
			break;
		case 'fat32':
			return "/sbin/mkfs.fat -s 8 -F 32 {$dev}";
			break;
		default:
			return false;
			break;
	}
}

function format_partition($partition, $fs) {
	$part = get_partition_info($partition);
	if ( $part['fstype'] && $part['fstype'] != "precleared" ) {
		unassigned_log("Aborting format: partition '{$partition}' is already formatted with '{$part[fstype]}' filesystem.");
		return NULL;
	}
	unassigned_log("Formatting partition '{$partition}' with '$fs' filesystem.");
	$o = trim(shell_exec(get_format_cmd($partition, $fs)));
	if ($o != "") {
		unassigned_log("Format partition '{$partition}' with '$fs' filesystem result:\n$o");
	}

	sleep(3);
	$disk = preg_replace("@\d+@", "", $partition);
	$o = trim(shell_exec("/usr/sbin/hdparm -z {$disk}"));
	if ($o != "") {
		unassigned_log("Reload partition table result:\n$o");
	}

	sleep(3);
	@touch($GLOBALS['paths']['reload']);
}

function format_disk($dev, $fs) {
	# making sure it doesn't have partitions
	foreach (get_all_disks_info() as $d) {
		if ($d['device'] == $dev && count($d['partitions']) && $d['partitions'][0]['fstype'] != "precleared") {
			unassigned_log("Aborting format: disk '{$dev}' has '".count($d['partitions'])."' partition(s).");
			return FALSE;
		}
	}
	$max_mbr_blocks = hexdec("0xFFFFFFFF");
	$disk_blocks    = intval(trim(shell_exec("/sbin/blockdev --getsz $dev  | /bin/awk '{ print $1 }'")));
	$disk_schema    = ( $disk_blocks >= $max_mbr_blocks ) ? "gpt" : "msdos";
	switch ($fs) {
		case 'exfat':
			$parted_fs = "fat32";
			break;
		default:
			$parted_fs = $fs;
			break;
	}
	unassigned_log("Device '{$dev}' block size: {$disk_blocks}");

	unassigned_log("Clearing partition table of disk '$dev'.");
	$o = trim(shell_exec("/usr/bin/dd if=/dev/zero of={$dev} bs=2M count=1 2>&1"));
	if ($o != "") {
		unassigned_log("Clear partition result:\n$o");
	}

	unassigned_log("Reloading disk '{$dev}' partition table.");
	$o = trim(shell_exec("/usr/sbin/hdparm -z {$dev} 2>&1"));
	if ($o != "") {
		unassigned_log("Reload partition table result:\n$o");
	}

	unassigned_log("Creating a '{$disk_schema}' partition table on disk '{$dev}'.");
	$o = trim(shell_exec("/usr/sbin/parted {$dev} --script -- mklabel {$disk_schema} 2>&1"));
	if ($o != "") {
		unassigned_log("Create '{$disk_schema}' partition table result:\n$o");
	}

	unassigned_log("Creating a primary partition on disk '{$dev}'.");
	$o = trim(shell_exec("/usr/sbin/parted -a optimal {$dev} --script -- mkpart primary $parted_fs 0% 100% 2>&1"));
	if ($o != "") {
		unassigned_log("Create primary partition result:\n$o");
	}

	unassigned_log("Formatting disk '{$dev}' with '$fs' filesystem.");
	$o = trim(shell_exec(get_format_cmd("{$dev}1", $fs)));
	if ($o != "") {
		unassigned_log("Format disk '{$dev}' with '$fs' filesystem result:\n$o");
	}

	sleep(3);
	unassigned_log("Reloading disk '{$dev}' partition table.");
	$o = trim(shell_exec("/usr/sbin/hdparm -z {$dev} 2>&1"));
	if ($o != "") {
		unassigned_log("Reload partition table result:\n$o");
	}

	sleep(3);
	@touch($GLOBALS['paths']['reload']);
}

function remove_partition($dev, $part) {
	foreach (get_all_disks_info() as $d) {
		if ($d['device'] == $dev) {
			foreach ($d['partitions'] as $p) {
				if ($p['part'] == $part && $p['target']) {
					unassigned_log("Aborting removal: partition '{$part}' is mounted.");
					return FALSE;
				} 
			}
		}
	}
	unassigned_log("Removing partition '{$part}' from disk '{$dev}'.");
	shell_exec("/usr/sbin/parted {$dev} --script -- rm {$part}");
}

#########################################################
############        CONFIG FUNCTIONS        #############
#########################################################

function get_config($sn, $var) {
	$config_file = $GLOBALS["paths"]["config_file"];
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	return  (isset($config[$sn][$var])) ? html_entity_decode($config[$sn][$var]) : FALSE;
}

function set_config($sn, $var, $val) {
	$config_file = $GLOBALS["paths"]["config_file"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0666,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	$config[$sn][$var] = htmlentities($val, ENT_COMPAT);
	save_ini_file($config_file, $config);
	return (isset($config[$sn][$var])) ? $config[$sn][$var] : FALSE;
}

function is_automount($sn, $usb=FALSE) {
	$auto = get_config($sn, "automount");
	$auto_usb = get_config("Config", "automount_usb");
	return ($auto == "yes" || ( ! $auto && $usb !== FALSE && $auto_usb == "yes" ) ) ? TRUE : FALSE;
}

function toggle_automount($sn, $status) {
	$config_file = $GLOBALS["paths"]["config_file"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0777,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	$config[$sn]["automount"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$sn]["automount"] == "yes") ? TRUE : FALSE;
}

function execute_script($info, $action, $testing = FALSE) { 
	$out = ''; 
	$error = '';
	putenv("ACTION=${action}");
	foreach ($info as $key => $value) {
		putenv(strtoupper($key)."=${value}");
	}
	$cmd = $info['command'];
	$bg = ($info['command_bg'] == "true" && $action == "ADD") ? "&" : "";
	if ($common_cmd = get_config("Config", "common_cmd")) {
		unassigned_log("Running common script: '{$common_cmd}'");
		exec($common_cmd);
	}
	if (! $cmd) {unassigned_log("Device '${info[device]}' script file not found.  '${action}' script not executed."); return FALSE;}
	unassigned_log("Running command '${cmd}' with action '${action}'.");
	if (! is_executable($cmd) ) {
		@chmod($cmd, 0755);
	}
	if (! $testing) {
		$cmd = isset($info['serial']) ? "$cmd > /tmp/${info[serial]}.log 2>&1 $bg" : "$cmd > /tmp/".preg_replace('~[^\w]~i', '', $info['device']).".log 2>&1 $bg";
		exec($cmd);
	} else {
		return $cmd;
	}
}

function set_command($sn, $cmd) {
	$config_file = $GLOBALS["paths"]["config_file"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0666,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	$config[$sn]["command"] = htmlentities($cmd, ENT_COMPAT);
	save_ini_file($config_file, $config);
	return (isset($config[$sn]["command"])) ? TRUE : FALSE;
}

function remove_config_disk($sn) {
	$config_file = $GLOBALS["paths"]["config_file"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0666,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	if ( isset($config[$source]) ) {
		unassigned_log("Removing configuration '$source'.");
	}
	$command = $config[$source][command];
	if ( isset($command) && is_file($command) ) {
		@unlink($command);
		unassigned_log("Removing script '$command'.");
	}
	unset($config[$sn]);
	save_ini_file($config_file, $config);
	return (isset($config[$sn])) ? TRUE : FALSE;
}

#########################################################
############        MOUNT FUNCTIONS        ##############
#########################################################

function is_mounted($dev, $dir=FALSE) {
	$data = shell_exec("/sbin/mount");
	$append = ($dir) ? " " : " on";
	return strpos($data, $dev.$append);
}

function get_mount_params($fs, $dev) {
	$discard = trim(shell_exec("/usr/bin/cat /sys/block/".preg_replace("#\d+#i", "", basename($dev))."/queue/discard_max_bytes 2>/dev/null")) ? ",discard" : "";
	switch ($fs) {
		case 'hfsplus':
			return "force,rw,users,async,umask=000";
			break;
		case 'xfs':
			return "rw,noatime,nodiratime{$discard}";
			break;
		case 'exfat':
		case 'vfat':
		case 'ntfs':
			return "auto,async,noatime,nodiratime,nodev,nosuid,umask=000";
			break;
		case 'ext4':
			return "auto,noatime,nodiratime,async,nodev,nosuid{$discard}";
			break;
		case 'cifs':
			return "rw,nounix,iocharset=utf8,_netdev,file_mode=0777,dir_mode=0777,username=%s,password=%s";
			break;
		case 'nfs':
			return "defaults";
			break;
		default:
			return "auto,async,noatime,nodiratime";
			break;
	}
}

function do_mount($info) {
	if ($info['fstype'] == "cifs" || $info['fstype'] == "nfs") {
		return do_mount_samba($info);
	} else if($info['fstype'] == "loop") {
		return do_mount_iso($info);
	} else {
		return do_mount_local($info);
	}
}

function do_mount_local($info) {
	$dev = $info['device'];
	$dir = $info['mountpoint'];
	$fs  = $info['fstype'];
	if (! is_mounted($dev) || ! is_mounted($dir, true)) {
		if ($fs){
			@mkdir($dir, 0777, TRUE);
			$cmd = "/sbin/mount -t $fs -o ".get_mount_params($fs, $dev)." '${dev}' '${dir}'";
			unassigned_log("Mount drive command: $cmd");
			$o = shell_exec($cmd." 2>&1");
			if ($o != "" && $fs == "ntfs") {
				unassigned_log("Mount failed with error: $o");
				unassigned_log("Mount ntfs drive read only.");
				$cmd = "/sbin/mount -t $fs -r -o ".get_mount_params($fs, $dev)." '${dev}' '${dir}'";
				unassigned_log("Mount drive ro command: $cmd");
				$o = shell_exec($cmd." 2>&1");
				if ($o != "") {
					unassigned_log("Mount ntfs drive read only failed with error: $o");
				}
			}
			foreach (range(0,5) as $t) {
				if (is_mounted($dev)) {
					@chmod($dir, 0777);@chown($dir, 99);@chgrp($dir, 100);
					unassigned_log("Successfully mounted '${dev}' on '${dir}'.");
					return TRUE;
				} else {
					sleep(0.5);
				}
			}
			unassigned_log("Mount of '${dev}' failed. Error message: $o");
			rmdir($dir);
			return FALSE;
		} else {
			unassigned_log("No filesystem detected, aborting.");
		}
	} else {
		unassigned_log("Drive '$dev' already mounted...");
	}
}

function do_unmount($dev, $dir, $force = FALSE) {
	if ( is_mounted($dev) ) {
		unassigned_log("Unmounting '${dev}'...");
		$o = shell_exec("/bin/umount".($force ? " -f -l" : "")." '${dev}' 2>&1");
		for ($i=0; $i < 10; $i++) {
			if (! is_mounted($dev)) {
				if (is_dir($dir)) rmdir($dir);
				unassigned_log("Successfully unmounted '$dev'");
				return TRUE;
			} else {
				sleep(0.5);
			}
		}
		unassigned_log("Unmount of '${dev}' failed. Error message: \n$o"); 
		sleep(1);
		if (! lsof($dir) && ! $force) {
			unassigned_log("Since there aren't open files, try to force unmount.");
			return do_unmount($dev, $dir, true);
		}
		return FALSE;
	}
}

#########################################################
############        SHARE FUNCTIONS         #############
#########################################################

function is_shared($name) {
	return ( shell_exec("/usr/bin/smbclient -g -L localhost -U% 2>&1|/bin/awk -F'|' '/Disk/{print $2}'|/bin/grep -c '${name}'") == 0 ) ? FALSE : TRUE;
}

function config_shared($sn, $part, $usb=FALSE) {
	$share = get_config($sn, "share.{$part}");
	$auto_usb = get_config("Config", "automount_usb");
	return ($share == "yes" || (! $share && $usb == TRUE && $auto_usb == "yes")) ? TRUE : FALSE; 
}

function toggle_share($serial, $part, $status) {
	$new = ($status == "true") ? "yes" : "no";
	set_config($serial, "share.{$part}", $new);
	return ($new == 'yes') ? TRUE:FALSE;
}

function add_smb_share($dir, $share_name, $recycle_bin=TRUE) {
	global $paths, $var, $users;

	if ( ($var['shareSMBEnabled'] == "yes") ) {
		$share_name = basename($dir);
		$config = is_file($paths['config_file']) ? @parse_ini_file($paths['config_file'], true) : array();
		$config = $config["Config"];

		if ($recycle_bin) {
			$vfs_objects = "\n\tvfs objects = ";
		} else {
			$vfs_objects = "";
		}
		if (($config["smb_security"] == "yes") || ($config["smb_security"] == "hidden")) {
			$read_users = $write_users = $valid_users = array();
			foreach ($users as $key => $user) {
				if ($user['name'] != "root" ) {
					$valid_users[] = $user['name'];
				}
			}

			$invalid_users = array_filter($valid_users, function($v) use($config, &$read_users, &$write_users) { 
				if ($config["smb_{$v}"] == "read-only") {$read_users[] = $v;}
				elseif ($config["smb_{$v}"] == "read-write") {$write_users[] = $v;}
				else {return $v;}
			});
			$valid_users = array_diff($valid_users, $invalid_users);
			if (count($valid_users)) {
				if ($config["smb_security"] == "hidden") {
					$hidden = "\n\tbrowseable = no";
				} else {
					$hidden = "\n\tbrowseable = yes";
				}
				$valid_users = "\n\tvalid users = ".implode(', ', $valid_users);
				$write_users = count($write_users) ? "\n\twrite list = ".implode(', ', $write_users) : "";
				$read_users = count($read_users) ? "\n\tread users = ".implode(', ', $read_users) : "";
				$share_cont =  "[{$share_name}]\n\tpath = {$dir}{$hidden}{$valid_users}{$write_users}{$read_users}{$vfs_objects}";
			} else {
				$share_cont =  "[{$share_name}]\n\tpath = {$dir}\n\tinvalid users = @users";
				unassigned_log("Error: No valid smb users defined.  Share '{$dir}' cannot be accessed.");
			}
		} else {
			$share_cont = "[{$share_name}]\n\tpath = {$dir}\n\tread only = No\n\tguest ok = Yes{$vfs_objects}";
		}

		if(!is_dir($paths['smb_usb_shares'])) @mkdir($paths['smb_usb_shares'],0755,TRUE);
		$share_conf = preg_replace("#\s+#", "_", realpath($paths['smb_usb_shares'])."/".$share_name.".conf");

		unassigned_log("Defining share '$share_name' on file '$share_conf'");
		file_put_contents($share_conf, $share_cont);
		if (! exist_in_file($paths['smb_extra'], $share_conf)) {
			unassigned_log("Adding share '$share_name' to '".$paths['smb_extra']."'");
			$c = (is_file($paths['smb_extra'])) ? @file($paths['smb_extra'],FILE_IGNORE_NEW_LINES) : array();
			$c[] = ""; $c[] = "include = $share_conf";
			# Do Cleanup
			$smb_extra_includes = array_unique(preg_grep("/include/i", $c));
			foreach($smb_extra_includes as $key => $inc) if( ! is_file(parse_ini_string($inc)['include'])) unset($smb_extra_includes[$key]); 
			$c = array_merge(preg_grep("/include/i", $c, PREG_GREP_INVERT), $smb_extra_includes);
			$c = preg_replace('/\n\s*\n\s*\n/s', PHP_EOL.PHP_EOL, implode(PHP_EOL, $c));
			file_put_contents($paths['smb_extra'], $c);

			if ($recycle_bin) {
				// Add the recycle bin parameters if plugin is installed installed
				$recycle_script = "/usr/local/emhttp/plugins/recycle.bin/scripts/configure_recycle_bin";
				if (is_file($recycle_script)) {
					unassigned_log("Enabling the Recycle Bin on share '$share_name'");
					shell_exec("$recycle_script"." ${share_conf}");
				}
			}
		}

		unassigned_log("Reloading Samba configuration...");
		shell_exec("/usr/bin/smbcontrol $(cat /var/run/smbd.pid 2>/dev/null) reload-config 2>&1");
		unassigned_log("Directory '${dir}' shared successfully."); return TRUE;
	} else {
		return TRUE;
	}
}

function rm_smb_share($dir, $share_name) {
	global $paths, $var;

	if ( ($var['shareSMBEnabled'] == "yes") ) {
		$share_name = basename($dir);
		$share_conf = preg_replace("#\s+#", "_", realpath($paths['smb_usb_shares'])."/".$share_name.".conf");
		if (is_file($share_conf)) {
			@unlink($share_conf);
			unassigned_log("Removing SMB share file '$share_conf'");
		}
		if (exist_in_file($paths['smb_extra'], $share_conf)) {
			unassigned_log("Removing SMB share definitions from '".$paths['smb_extra']."'");
			$c = (is_file($paths['smb_extra'])) ? @file($paths['smb_extra'],FILE_IGNORE_NEW_LINES) : array();
			# Do Cleanup
			$smb_extra_includes = array_unique(preg_grep("/include/i", $c));
			foreach($smb_extra_includes as $key => $inc) if(! is_file(parse_ini_string($inc)['include'])) unset($smb_extra_includes[$key]); 
			$c = array_merge(preg_grep("/include/i", $c, PREG_GREP_INVERT), $smb_extra_includes);
			$c = preg_replace('/\n\s*\n\s*\n/s', PHP_EOL.PHP_EOL, implode(PHP_EOL, $c));
			file_put_contents($paths['smb_extra'], $c);

			unassigned_log("Reloading Samba configuration..");
			shell_exec("/usr/bin/smbcontrol $(/usr/bin/cat /var/run/smbd.pid 2>/dev/null) close-share '${share_name}' 2>&1");
			shell_exec("/usr/bin/smbcontrol $(/usr/bin/cat /var/run/smbd.pid 2>/dev/null) reload-config 2>&1");
			unassigned_log("Successfully removed SMB share '${share_name}'."); return TRUE;
		} else {
			return TRUE;
		}
	} else {
		return TRUE;
	}
}

function add_nfs_share($dir) {
	global $var;

	if ( ($var['shareNFSEnabled'] == "yes") && (get_config("Config", "nfs_export") == "yes") ) {
		$reload = FALSE;
		foreach (array("/etc/exports","/etc/exports-") as $file) {
			if (! exist_in_file($file, "\"{$dir}\"")) {
				$c = (is_file($file)) ? @file($file,FILE_IGNORE_NEW_LINES) : array();
				unassigned_log("Adding NFS share '$dir' to '$file'.");
				$fsid = 200 + count(preg_grep("@^\"@", $c));
				$nfs_sec = get_config("Config", "nfs_security");
				$sec = "";
				if ( $nfs_sec == "private" ) {
					$sec = get_config("Config", "nfs_rule");
				} else {
					$sec = "*(sec=sys,rw,insecure,anongid=100,anonuid=99,all_squash)";
				}
				$c[] = "\"{$dir}\" -async,no_subtree_check,fsid={$fsid} {$sec}";
				$c[] = "";
				file_put_contents($file, implode(PHP_EOL, $c));
				$reload = TRUE;
			}
		}
		if ($reload) shell_exec("/usr/sbin/exportfs -ra 2>/dev/null");
	}
	return TRUE;
}

function rm_nfs_share($dir) {
	global $var;

	if ( ($var['shareNFSEnabled'] == "yes") && (get_config("Config", "nfs_export") == "yes") ) {
		$reload = FALSE;
		foreach (array("/etc/exports","/etc/exports-") as $file) {
			if ( exist_in_file($file, "\"{$dir}\"") && strlen($dir)) {
				$c = (is_file($file)) ? @file($file,FILE_IGNORE_NEW_LINES) : array();
				unassigned_log("Removing NFS share '$dir' from '$file'.");
				$c = preg_grep("@\"{$dir}\"@i", $c, PREG_GREP_INVERT);
				$c[] = "";
				file_put_contents($file, implode(PHP_EOL, $c));
				$reload = TRUE;
			}
		}
		if ($reload) shell_exec("/usr/sbin/exportfs -ra 2>/dev/null");
	}
	return TRUE;
}


function remove_shares() {
	// Disk mounts
	foreach (get_unasigned_disks() as $name => $disk) {
		foreach ($disk['partitions'] as $p) {
			if ( is_mounted(realpath($p), true) ) {
				$info = get_partition_info($p);
				$attrs = (isset($_ENV['DEVTYPE'])) ? get_udev_info($device, $_ENV, $reload) : get_udev_info($device, NULL, $reload);
				if (config_shared( $info['serial'], $info['part'], strpos($attrs['DEVPATH'],"usb"))) {
					unassigned_log("Reloading shared dir '{$info[target]}'.");
					unassigned_log("Removing old config...");
					rm_smb_share($info['target'], $info['label']);
					rm_nfs_share($info['target']);
				}
			}
		}
	}

	// SMB Mounts
	foreach (get_samba_mounts() as $name => $info) {
		if ( is_mounted($info['device']) ) {
			unassigned_log("Reloading shared dir '{$info[mountpoint]}'.");
			unassigned_log("Removing old config...");
			rm_smb_share($info['mountpoint'], $info['device']);
		}
	}

	// Iso File Mounts
	foreach (get_iso_mounts() as $name => $info) {
		if ( is_mounted($info['device']) ) {
			unassigned_log("Reloading shared dir '{$info[mountpoint]}'.");
			unassigned_log("Removing old config...");
			rm_smb_share($info['mountpoint'], $info['device']);
			rm_nfs_share($info['mountpoint']);
		}
	}
}

function reload_shares() {
	// Disk mounts
	foreach (get_unasigned_disks() as $name => $disk) {
		foreach ($disk['partitions'] as $p) {
			if ( is_mounted(realpath($p), true) ) {
				$info = get_partition_info($p);
				$attrs = (isset($_ENV['DEVTYPE'])) ? get_udev_info($device, $_ENV, $reload) : get_udev_info($device, NULL, $reload);
				if (config_shared( $info['serial'], $info['part'], strpos($attrs['DEVPATH'],"usb"))) {
					unassigned_log("Adding new config...");
					add_smb_share($info['mountpoint'], $info['label']);
					add_nfs_share($info['mountpoint']);
				}
			}
		}
	}

	// SMB Mounts
	foreach (get_samba_mounts() as $name => $info) {
		if ( is_mounted($info['device']) ) {
			add_smb_share($info['mountpoint'], $info['device'], FALSE);
		}
	}

	// Iso File Mounts
	foreach (get_iso_mounts() as $name => $info) {
		if ( is_mounted($info['device']) ) {
			add_smb_share($info['mountpoint'], $info['device'], FALSE);
			add_nfs_share($info['mountpoint']);
		}
	}
}

#########################################################
############        SAMBA FUNCTIONS         #############
#########################################################

function get_samba_config($source, $var) {
	$config_file = $GLOBALS["paths"]["samba_mount"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0666,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	return (isset($config[$source][$var])) ? $config[$source][$var] : FALSE;
}

function set_samba_config($source, $var, $val) {
	$config_file = $GLOBALS["paths"]["samba_mount"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0666,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	$config[$source][$var] = $val;
	save_ini_file($config_file, $config);
	return (isset($config[$source][$var])) ? $config[$source][$var] : FALSE;
}

function is_samba_automount($sn) {
	$auto = get_samba_config($sn, "automount");
	return ( ($auto) ? ( ($auto == "yes") ? TRUE : FALSE ) : TRUE);
}

function get_samba_mounts() {
	global $paths;

	$o = array();
	$config_file = $GLOBALS["paths"]["samba_mount"];
	$samba_mounts = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	foreach ($samba_mounts as $device => $mount) {
		$mount['device'] = $device;
		if ($mount['protocol'] == "NFS") {
			$mount['fstype'] = "nfs";
		} else {
			$mount['fstype'] = "cifs";
		}

		$mount['automount'] = is_samba_automount($mount['device']);
		if (! $mount["mountpoint"]) {
			$mount["mountpoint"] = $mount['target'] ? $mount['target'] : preg_replace("%\s+%", "_", "{$paths[usb_mountpoint]}/{$mount[ip]}_{$mount[share]}");
		}
		$is_alive = (trim(exec("/bin/ping -c 1 -W 1 {$mount[ip]} >/dev/null 2>&1; echo $?")) == 0 ) ? TRUE : FALSE;
		$mount['size']   = intval(trim($is_alive ? shell_exec("/bin/df '${mount[mountpoint]}' --output=size,source 2>/dev/null|/bin/grep -v 'Filesystem'|/bin/awk '{print $1}'"):"0"))*1024;
		$mount['used']   = intval(trim($is_alive ? shell_exec("/bin/df '${mount[mountpoint]}' --output=used,source 2>/dev/null|/bin/grep -v 'Filesystem'|/bin/awk '{print $1}'"):"0"))*1024;
		$mount['avail']  = $mount['size'] - $mount['used'];
		$mount['target'] = trim(shell_exec("/bin/cat /proc/mounts 2>&1|/bin/grep '$mount[mountpoint] '|/bin/awk '{print $2}'"));
		$mount['prog_name'] = basename($mount['command'], ".sh");
		$mount['logfile'] = $paths['device_log'].$mount['prog_name'].".log";
		$o[] = $mount;
	}
	return $o;
}

function do_mount_samba($info) {
	global $var;

	$is_alive = (trim(exec("/bin/ping -c 1 -W 1 {$info[ip]} >/dev/null 2>&1; echo $?")) == 0 ) ? TRUE : FALSE;
	if ($is_alive) {
		if (!(($info[fstype] == "nfs") && ((strtoupper($var['NAME']) == strtoupper($info['ip'])) || ($var['IPADDR'] == $info['ip'])))) {
			$dev = $info['device'];
			$dir = $info['mountpoint'];
			$fs  = $info['fstype'];
			if (! is_mounted($dev) || ! is_mounted($dir, true)) {
				@mkdir($dir, 0777, TRUE);
				if ($fs == "nfs") {
					$params = get_mount_params($fs, '$dev');
					$cmd = "/sbin/mount -t $fs -o ".$params." '${dev}' '${dir}'";
					$o = shell_exec($cmd." 2>&1");
				} else {
					$params = sprintf(get_mount_params($fs, '$dev'), ($info['user'] ? $info['user'] : "guest" ), $info['pass']);
					$cmd = "/sbin/mount -t $fs -o ".$params." '${dev}' '${dir}'";
					$o = shell_exec($cmd." 2>&1");
					$params = sprintf(get_mount_params($fs, '$dev'), ($info['user'] ? $info['user'] : "guest" ), '*******');
				}
				unassigned_log("Mount SMB/NFS command: mount -t $fs -o ".$params." '${dev}' '${dir}'");
				foreach (range(0,5) as $t) {
					if (is_mounted($dev)) {
						@chmod($dir, 0777);@chown($dir, 99);@chgrp($dir, 100);
						unassigned_log("Successfully mounted '${dev}' on '${dir}'.");
						return TRUE;
					} else {
						sleep(0.5);
					}
				}
				rmdir($dir);
				unassigned_log("Mount of '${dev}' failed. Error message: $o");
				return FALSE;
			} else {
				unassigned_log("Share '${dev}' already mounted.");
				return FALSE;
			}
		} else {
			unassigned_log("Error: Cannot mount remote NFS '${info[device]}' from this server onto this server."); 
			return FALSE;
		}
	} else {
		unassigned_log("Error: Remote SMB/NFS server '${info[ip]}' is offline and share '${info[device]}' cannot be mounted."); 
		return FALSE;
	}
}

function toggle_samba_automount($source, $status) {
	$config_file = $GLOBALS["paths"]["samba_mount"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0777,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	$config[$source]["automount"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$source]["automount"] == "yes") ? TRUE : FALSE;
}

function remove_config_samba($source) {
	$config_file = $GLOBALS["paths"]["samba_mount"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0666,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	if ( isset($config[$source]) ) {
		unassigned_log("Removing configuration '$source'.");
	}
	$command = $config[$source][command];
	if ( isset($command) && is_file($command) ) {
		@unlink($command);
		unassigned_log("Removing script '$command'.");
	}
	unset($config[$source]);
	save_ini_file($config_file, $config);
	return (isset($config[$source])) ? TRUE : FALSE;
}

#########################################################
############      ISO FILE FUNCTIONS        #############
#########################################################

function get_iso_config($source, $var) {
	$config_file = $GLOBALS["paths"]["iso_mount"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0666,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	return (isset($config[$source][$var])) ? $config[$source][$var] : FALSE;
}

function set_iso_config($source, $var, $val) {
	$config_file = $GLOBALS["paths"]["iso_mount"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0666,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	$config[$source][$var] = $val;
	save_ini_file($config_file, $config);
	return (isset($config[$source][$var])) ? $config[$source][$var] : FALSE;
}

function is_iso_automount($sn) {
	$auto = get_iso_config($sn, "automount");
	return ( ($auto) ? ( ($auto == "yes") ? TRUE : FALSE ) : TRUE);
}

function get_iso_mounts() {
	global $paths;

	$o = array();
	$config_file = $GLOBALS["paths"]["iso_mount"];
	$iso_mounts = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	foreach ($iso_mounts as $device => $mount) {
		$mount['device'] = $device;
		$mount['fstype'] = "loop";
		$mount['automount'] = is_iso_automount($mount['device']);
		if (! $mount["mountpoint"]) {
			$mount["mountpoint"] = preg_replace("%\s+%", "_", "{$paths[usb_mountpoint]}/{$mount[share]}");
		}
		$mount['target'] = trim(shell_exec("/bin/cat /proc/mounts 2>&1|/bin/grep '$mount[mountpoint] '|/bin/awk '{print $2}'"));
		$is_alive = is_file($mount['file']);
		$mount['size']   = intval(trim($is_alive ? shell_exec("/bin/df '$mount[mountpoint]' --output=size,source 2>/dev/null|/bin/grep -v 'Filesystem'|/bin/awk '{print $1}'"):"0"))*1024;
		$mount['used']   = intval(trim($is_alive ? shell_exec("/bin/df '$mount[mountpoint]' --output=used,source 2>/dev/null|/bin/grep -v 'Filesystem'|/bin/awk '{print $1}'"):"0"))*1024;
		$mount['avail']  = $mount['size'] - $mount['used'];
		$mount['prog_name'] = basename($mount['command'], ".sh");
		$mount['logfile'] = $paths['device_log'].$mount['prog_name'].".log";
		$o[] = $mount;
	}
	return $o;
}

function do_mount_iso($info) {
	$dev = $info['device'];
	$dir = $info['mountpoint'];
	if (is_file($info['file'])) {
		if (! is_mounted($dev) || ! is_mounted($dir, true)) {
			@mkdir($dir, 0777, TRUE);
			$cmd = "/sbin/mount -t iso9660 -o loop '${dev}' '${dir}'";
			unassigned_log("Mount iso command: mount -t iso9660 -o loop '${dev}' '${dir}'");
			$o = shell_exec($cmd." 2>&1");
			foreach (range(0,5) as $t) {
				if (is_mounted($dev)) {
					@chmod($dir, 0777);@chown($dir, 99);@chgrp($dir, 100);
					unassigned_log("Successfully mounted '${dev}' on '${dir}'.");
					return TRUE;
				} else {
					sleep(0.5);
				}
			}
			rmdir($dir);
			unassigned_log("Mount of '${dev}' failed. Error message: $o");
			return FALSE;
		} else {
			unassigned_log("Share '$dev' already mounted...");
		}
	} else {
		unassigned_log("Error: Iso file '$info[file]' is missing and cannot be mounted.");
		return FALSE;
	}
}

function toggle_iso_automount($source, $status) {
	$config_file = $GLOBALS["paths"]["iso_mount"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0777,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	$config[$source]["automount"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$source]["automount"] == "yes") ? TRUE : FALSE;
}

function remove_config_iso($source) {
	$config_file = $GLOBALS["paths"]["iso_mount"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0666,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	if ( isset($config[$source]) ) {
		unassigned_log("Removing configuration '$source'.");
	}
	$command = $config[$source][command];
	if ( isset($command) && is_file($command) ) {
		@unlink($command);
		unassigned_log("Removing script '$command'.");
	}
	unset($config[$source]);
	save_ini_file($config_file, $config);
	return (isset($config[$source])) ? TRUE : FALSE;
}


#########################################################
############         DISK FUNCTIONS         #############
#########################################################

function get_unasigned_disks() {
	global $disks, $var;

	$ud_disks = $paths = $unraid_disks = array();

	// Get all devices by id.
	foreach (listDir("/dev/disk/by-id/") as $p) {
		$r = realpath($p);
		// Only /dev/sd*, /dev/hd* and /dev/nvme* devices.
		if (!is_bool(strpos($r, "/dev/sd")) || !is_bool(strpos($r, "/dev/hd")) || !is_bool(strpos($r, "/dev/nvme"))) {
			$paths[$r] = $p;
		}
	}
	natsort($paths);

	// Get the flash device.
	$unraid_disks[] = "/dev/".$disks['flash']['device'];

	// Get the array devices.
	for ($i = 1; $i < $var['SYS_ARRAY_SLOTS']; $i++) {
		if (isset($disks["disk".$i]['device'])) {
			$dev = $disks["disk".$i]['device'];
			$unraid_disks[] = "/dev/".$dev;
		}
	}

	// Get the parity devices.
	if ( $disks['parity']['device'] != "") {
		$unraid_disks[] = "/dev/".$disks['parity']['device'];
	}
	if ( isset($disks['parity2']['device']) && $disks['parity2']['device'] != "") {
		$unraid_disks[] = "/dev/".$disks['parity2']['device'];
	}

	// Get all cache devices.
	if ( $disks['cache']['device'] != "") {
		$unraid_disks[] = "/dev/".$disks['cache']['device'];
	}
	for ($i = 2; $i <= $var['SYS_CACHE_SLOTS']; $i++) {
		if (isset($disks["cache".$i]['device'])) {
			$dev = $disks["cache".$i]['device'];
			$unraid_disks[] = "/dev/".$dev;
		}
	}
	foreach ($unraid_disks as $k) {$o .= "  $k\n";}; unassigned_log("UNRAID DISKS:\n$o", "DEBUG");

	// Create the array of unassigned devices.
	foreach ($paths as $path => $d) {
		if (preg_match("#^(.(?!wwn|part))*$#", $d)) {
			if (! in_array($path, $unraid_disks)) {
				if (in_array($path, array_map(function($ar){return $ar['device'];}, $ud_disks)) ) continue;
				$m = array_values(preg_grep("|$d.*-part\d+|", $paths));
				natsort($m);
				$ud_disks[$d] = array("device"=>$path,"type"=>"ata", "partitions"=>$m);
				unassigned_log("Unassigned disk: '$d'.", "DEBUG");
			} else {
				unassigned_log("Discarded: => '$d' '($path)'.", "DEBUG");
				continue;
			}
		}
	}
	return $ud_disks;
}

function get_all_disks_info($bus="all") {
	unassigned_log("Starting get_all_disks_info.", "DEBUG");
	$d1 = time();
	$ud_disks = get_unasigned_disks();
	foreach ($ud_disks as $key => $disk) {
		if ($disk['type'] != $bus && $bus != "all") continue;
		$disk['temperature'] = "*";
		$disk['size'] = intval(trim(shell_exec("/sbin/blockdev --getsize64 ${key} 2>/dev/null")));
		$disk = array_merge($disk, get_disk_info($key));
		foreach ($disk['partitions'] as $k => $p) {
			if ($p) $disk['partitions'][$k] = get_partition_info($p);
		}
		$ud_disks[$key] = $disk;
	}
	unassigned_log("Total time: ".(time() - $d1)."s", "DEBUG");
	usort($ud_disks, create_function('$a, $b','$key="device";if ($a[$key] == $b[$key]) return 0; return ($a[$key] < $b[$key]) ? -1 : 1;'));
	return $ud_disks;
}

function get_udev_info($device, $udev=NULL, $reload) {
	global $paths;

	$state = is_file($paths['state']) ? @parse_ini_file($paths['state'], true) : array();
	if ($udev) {
		$state[$device] = $udev;
		save_ini_file($paths['state'], $state);
		return $udev;
	} else if (array_key_exists($device, $state) && ! $reload) {
		unassigned_log("Using udev cache for '$device'.", "DEBUG");
		return $state[$device];
	} else {
		$state[$device] = parse_ini_string(shell_exec("/sbin/udevadm info --query=property --path $(/sbin/udevadm info -q path -n $device 2>/dev/null) 2>/dev/null"));
		save_ini_file($paths['state'], $state);
		unassigned_log("Not using udev cache for '$device'.", "DEBUG");
		return $state[$device];
	}
}

function get_disk_info($device, $reload=FALSE){
	$disk = array();
	$attrs = (isset($_ENV['DEVTYPE'])) ? get_udev_info($device, $_ENV, $reload) : get_udev_info($device, NULL, $reload);
	$device = realpath($device);
	$disk['serial_short'] = isset($attrs["ID_SCSI_SERIAL"]) ? $attrs["ID_SCSI_SERIAL"] : $attrs['ID_SERIAL_SHORT'];
	$disk['serial']       = "{$attrs[ID_MODEL]}_{$disk[serial_short]}";
	$disk['device']       = $device;
	return $disk;
}

function get_partition_info($device, $reload=FALSE){
	global $_ENV, $paths;

	$disk = array();
	$attrs = (isset($_ENV['DEVTYPE'])) ? get_udev_info($device, $_ENV, $reload) : get_udev_info($device, NULL, $reload);
	// $GLOBALS["echo"]($attrs);
	$device = realpath($device);
	if ($attrs['DEVTYPE'] == "partition") {
		$disk['serial_short'] = isset($attrs["ID_SCSI_SERIAL"]) ? $attrs["ID_SCSI_SERIAL"] : $attrs['ID_SERIAL_SHORT'];
		$disk['serial']       = "{$attrs[ID_MODEL]}_{$disk[serial_short]}";
		$disk['device']       = $device;
		// Grab partition number
		preg_match_all("#(.*?)(\d+$)#", $device, $matches);
		$disk['part']   =  $matches[2][0];
		$disk['disk']   =  $matches[1][0];
		if (isset($attrs['ID_FS_LABEL'])){
			$disk['label'] = safe_name($attrs['ID_FS_LABEL_ENC']);
		} else {
			if (isset($attrs['ID_VENDOR']) && isset($attrs['ID_MODEL'])){
				$disk['label'] = sprintf("%s %s", safe_name($attrs['ID_VENDOR']), safe_name($attrs['ID_MODEL']));
			} else {
				$disk['label'] = safe_name($attrs['ID_SERIAL']);
			}
			$all_disks = array_unique(array_map(function($ar){return realpath($ar);},listDir("/dev/disk/by-id")));
			$disk['label']  = (count(preg_grep("%".$matches[1][0]."%i", $all_disks)) > 2) ? $disk['label']."-part".$matches[2][0] : $disk['label'];
		}
		$disk['fstype'] = safe_name($attrs['ID_FS_TYPE']);
		$disk['fstype'] = (! $disk['fstype'] && verify_precleared($disk['disk'])) ? "precleared" : $disk['fstype'];
		$disk['target'] = str_replace("\\040", " ", trim(shell_exec("/bin/cat /proc/mounts 2>&1|/bin/grep ${device}|/bin/awk '{print $2}'")));
		$disk['size']   = intval(trim(shell_exec("/sbin/blockdev --getsize64 ${device} 2>/dev/null")));
		$disk['used']   = intval(trim(shell_exec("/bin/df '${device}' --output=used,source 2>/dev/null|/bin/grep -v 'Filesystem'|/bin/awk '{print $1}'")))*1024;
		$disk['avail']  = $disk['size'] - $disk['used'];
		if ( $disk['mountpoint'] = get_config($disk['serial'], "mountpoint.{$disk[part]}") ) {
			if (! $disk['mountpoint'] ) goto empty_mountpoint;
		} else {
			empty_mountpoint:
			$disk['mountpoint'] = $disk['target'] ? $disk['target'] : preg_replace("%\s+%", "_", sprintf("%s/%s", $paths['usb_mountpoint'], $disk['label']));
		}
		$disk['owner'] = (isset($_ENV['DEVTYPE'])) ? "udev" : "user";
		$disk['automount'] = is_automount($disk['serial'], strpos($attrs['DEVPATH'],"usb"));
		$disk['shared'] = config_shared($disk['serial'], $disk['part'], strpos($attrs['DEVPATH'],"usb"));
		$disk['command'] = get_config($disk['serial'], "command.{$disk[part]}");
		$disk['command_bg'] = get_config($disk['serial'], "command_bg.{$disk[part]}");
		$disk['prog_name'] = basename($disk['command'], ".sh");
		$disk['logfile'] = $paths['device_log'].$disk['prog_name'].".log";
		return $disk;
	}
}

function get_fsck_commands($fs, $dev, $type = "ro") {
	switch ($fs) {
		case 'vfat':
			$cmd = array('ro'=>'/sbin/fsck -n %s','rw'=>'/sbin/fsck -a %s');
			break;
		case 'ntfs':
			$cmd = array('ro'=>'/bin/ntfsfix -n %s','rw'=>'/bin/ntfsfix -b -d %s');
			break;
		case 'hfsplus';
			$cmd = array('ro'=>'/usr/sbin/fsck.hfsplus -l %s','rw'=>'/usr/sbin/fsck.hfsplus -y %s');
			break;
		case 'xfs':
			$cmd = array('ro'=>'/sbin/xfs_repair -n %s','rw'=>'/sbin/xfs_repair %s');
			break;
		case 'exfat':
			$cmd = array('ro'=>'/usr/sbin/fsck.exfat %s','rw'=>'/usr/sbin/fsck.exfat %s');
			break;
		case 'btrfs':
			$cmd = array('ro'=>'/sbin/btrfs scrub start -B -R -d -r %s','rw'=>'/sbin/btrfs scrub start -B -R -d %s');
			break;
		case 'ext4':
			$cmd = array('ro'=>'/sbin/fsck.ext4 -vn %s','rw'=>'/sbin/fsck.ext4 -v -f -p %s');
			break;
		case 'reiserfs':
			$cmd = array('ro'=>'/sbin/reiserfsck --check %s','rw'=>'/sbin/reiserfsck --fix-fixable %s');
			break;
		default:
			$cmd = array('ro'=>false,'rw'=>false);
		break;
	}
	return $cmd[$type] ? sprintf($cmd[$type], $dev) : "";
}

function setSleepTime($device) {
	$device = preg_replace("/\d+$/", "", $device);
	shell_exec("/usr/sbin/hdparm -S180 $device 2>&1");
}
?>
