<?php

	/*
	* Author: Andreas Linde <mail@andreaslinde.de>
	*
	* Copyright (c) 2009-2011 Andreas Linde.
	* All rights reserved.
	*
	* Permission is hereby granted, free of charge, to any person
	* obtaining a copy of this software and associated documentation
	* files (the "Software"), to deal in the Software without
	* restriction, including without limitation the rights to use,
	* copy, modify, merge, publish, distribute, sublicense, and/or sell
	* copies of the Software, and to permit persons to whom the
	* Software is furnished to do so, subject to the following
	* conditions:
	*
	* The above copyright notice and this permission notice shall be
	* included in all copies or substantial portions of the Software.
	*
	* THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
	* EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES
	* OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
	* NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
	* HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
	* WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
	* FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
	* OTHER DEALINGS IN THE SOFTWARE.
	*/
	 
//
// Update crash log data for a crash
//
// This script is used by the remote symbolicate process to update
// the database with the symbolicated crash log data for a given
// crash id
//

require_once('../config.php');

function parseblock($matches, $appString) {
  $result_offset = "";
  //make sure $matches[1] exists
  if (is_array($matches) && count($matches) >= 2) {
    $result = explode("\n", $matches[1]);
    foreach ($result as $line) {
      // search for the first occurance of the application name
      if (strpos($line, $appString) !== false && strpos($line, "uncaught_exception_handler (PLCrashReporter.m:") === false) {
        preg_match('/[0-9]+\s+[^\s]+\s+([^\s]+) /', $line, $matches);

        if (count($matches) >= 2) {
          if ($result_offset != "")
            $result_offset .= "%";
          $result_offset .= $matches[1];
        }
      }
    }
  }

  return $result_offset;
}


$success = False;

$allowed_args = ',id,log,';

$link = mysql_connect($server, $loginsql, $passsql)
    or die('error');
mysql_select_db($base) or die('error');

foreach(array_keys($_POST) as $k) {
    $temp = ",$k,";
    if(strpos($allowed_args,$temp) !== false) { $$k = $_POST[$k]; }
}

if (!isset($id)) $id = "";
if (!isset($log)) $log = "";

echo  $id." ".$log."\n";

if ($id == "" || $log == "") {
	mysql_close($link);
	die('error');
}

$query = "UPDATE ".$dbcrashtable." SET log = '".mysql_real_escape_string($log)."' WHERE id = ".$id;
$result = mysql_query($query) or die('Error in SQL '.$dbcrashtable);

if ($result) {
	$query = "UPDATE ".$dbsymbolicatetable." SET done = 1 WHERE crashid = ".$id;
	$result = mysql_query($query) or die('Error in SQL '.$dbsymbolicatetable);
	
	if ($result) {
		$query = "SELECT bundleidentifier, applicationname, version, groupid FROM ".$dbcrashtable." WHERE id = ".$id;
		$result = mysql_query($query) or die('Error in SQL '.$query);
		
	    $numrows = mysql_num_rows($result);
		if ($numrows == 1) {
  	        $row = mysql_fetch_row($result);
			
			$bundleidentifier = $row[0];
			$applicationname = $row[1];
			$version = $row[2];
			$groupid = $row[3];
			
			if ($groupid == 0) {
				// If there's no group, try to find one
				
			  	// this stores the offset which we need for grouping
			  	$crash_offset = "";
			  	$appcrashtext = "";
  	
			  	preg_match('%Application Specific Information:.*?\n(.*?)\n\n%is', $log, $appcrashinfo);
			  	if (is_array($appcrashinfo) && count($appcrashinfo) == 2) {
			  	      $appcrashtext = str_replace("\\", "", $appcrashinfo[1]);
			  	      $appcrashtext = str_replace("'", "\'", $appcrashtext);
			  	    }
  	
			  	// extract the block which contains the data of the crashing thread
			  	    preg_match('%Last Exception Backtrace:\n(.*?)\n\n%is', $log, $matches);
			  	    $crash_offset = parseblock($matches, $applicationname);	
			  	    if ($crash_offset == "") {
			  	      $crash_offset = parseblock($matches, $bundleidentifier);
			  	    }
  	
			  	    if ($crash_offset == "") {
			  	      preg_match('%Thread [0-9]+ Crashed:.*?\n(.*?)\n\n%is', $log, $matches);
			  	      $crash_offset = parseblock($matches, $applicationname);	
			  	      if ($crash_offset == "") {
			  	        $crash_offset = parseblock($matches, $bundleidentifier);
			  	      }
			  	    }
			  	    if ($crash_offset == "") {
			  	      preg_match('%Thread [0-9]+ Crashed:\n(.*?)\n\n%is', $log, $matches);
			  	      $crash_offset = parseblock($matches, $applicationname);
			  	      if ($crash_offset == "") {
			  	        $crash_offset = parseblock($matches, $bundleidentifier);
			  	      }
			  	    }
  	
  	
				$success = True;
			  	// if the offset string is not empty, we try a grouping
			  	if (strlen($crash_offset) > 0) {
			  		// get all the known bug patterns for the current app version
			  		$query = "SELECT id, fix, amount, description FROM ".$dbgrouptable." WHERE bundleidentifier = '".$bundleidentifier."' and affected = '".$version."' and pattern = '".mysql_real_escape_string($crash_offset)."'";
			  		$result = mysql_query($query) or die(xml_for_result(FAILURE_SQL_FIND_KNOWN_PATTERNS));
  	
			  		$numrows = mysql_num_rows($result);
  	
			  		if ($numrows == 1) {
			  	        // assign this bug to the group
			  	        $row = mysql_fetch_row($result);
			  	        $groupid = $row[0];
			  	        $amount = $row[2];
			  	        $desc = $row[3];
  	
			  	        // update the occurances of this pattern
			  	        $query = "UPDATE ".$dbgrouptable." SET amount=amount+1, latesttimestamp = ".time()." WHERE id=".$groupid;
			  	        $result = mysql_query($query) or die(xml_for_result(FAILURE_SQL_UPDATE_PATTERN_OCCURANCES));
  	
			  	        if ($desc != "" && $appcrashtext != "") {
			  	          $desc = str_replace("'", "\'", $desc);
			  	          $noAddressDesc = preg_replace('/0x[0-9a-f]+/', '', $desc);
			  	          $noAddressCrashText = preg_replace('/0x[0-9a-f]+/', '', $appcrashtext);
			  	          if (strpos($noAddressDesc, $noAddressCrashText) === false) {
			  	            $appcrashtext = $desc."\n".$appcrashtext;
			  	            $query = "UPDATE ".$dbgrouptable." SET description='".mysql_real_escape_string($appcrashtext)."' WHERE id=".$groupid;
			  	            $result = mysql_query($query) or die(xml_for_result('Error in SQL '.$query));
			  	          }
			  	        }
  	  	
			  	        if ($notify_amount_group > 1 && $notify_amount_group == $amount && $notify >= NOTIFY_ACTIVATED) {
			  	          // send prowl notification
			  	          if ($push_activated) {
			  	            $prowl->push(array(
			  	          		'application'=>$applicationname,
			  	          		'event'=>'Critical Crash',
			  	          		'description'=>'Version '.$version.' Pattern '.$crash_offset.' has a MORE than '.$notify_amount_group.' crashes!\n Sent at ' . date('H:i:s'),
			  	          		'priority'=>0,
			  	            ),true);
			  	          }
  	
			  	          // send boxcar notification
			  	          if($boxcar_activated) {
			  	          	$boxcar = new Boxcar($boxcar_uid, $boxcar_pwd);
			  	          	print_r($boxcar->send($applicationname, 'Version '.$version.' Pattern '.$crash_offset.' has a MORE than '.$notify_amount_group.' crashes!\n Sent at ' . date('H:i:s')));
			  	          }
  	
			  	          // send email notification
			  	          if ($mail_activated) {
			  	            $subject = $applicationname.': Critical Crash';
  	      
			  	            if ($crash_url != '')
			  	              $url = "<".$crash_url."admin/crashes.php?bundleidentifier=".$bundleidentifier."&version=".$version."&groupid=".$groupid.">\n\n";
			  	            else
			  	              $url = "\n";
			  	            $message = "Version ".$version." Pattern ".$crash_offset." has MORE than ".$notify_amount_group." crashes!\n".$url."Sent at ".date('H:i:s');
			  	            // $message .= $c;
  	
			  	            mail($notify_emails, $subject, $message, 'From: '.$mail_from. "\r\n");
			  	          }
			  	        }
  	      
			  	      } else if ($numrows == 0) {
			  	        // create a new pattern for this bug and set amount of occurrances to 1
			  	        $query = "INSERT INTO ".$dbgrouptable." (bundleidentifier, affected, pattern, amount, latesttimestamp, description) values ('".$bundleidentifier."', '".$version."', '".$crash_offset."', 1, ".time().", '".$appcrashtext."')";
			  	        $result = mysql_query($query) or die(xml_for_result(FAILURE_SQL_ADD_PATTERN));
  	
			  	        $groupid = mysql_insert_id($link);
  	
			  	        if ($notify == NOTIFY_ACTIVATED) {
			  	          // send push notification
			  	          if ($push_activated) {
			  	            $prowl->push(array(
			  	              'application'=>$applicationname,
			  	              'event'=>'New Crash type',
			  	              'description'=>'Version '.$version.' has a new type of crash!\n Sent at ' . date('H:i:s'),
			  	              'priority'=>0,
			  	        		),true);
			  	        	}
  	
			  	          // send email notification
			  	          if ($mail_activated) {
			  	            $subject = $applicationname.': New Crash type';
  	
			  	            if ($crash_url != '')
			  	              $url = "<".$crash_url."admin/crashes.php?bundleidentifier=".$bundleidentifier."&version=".$version."&groupid=".$groupid.">\n\n";
			  	            else
			  	              $url = "\n";
			  	            $message = "Version ".$version." has a new type of crash!\n\n".$url."Sent at ".date('H:i:s');
			  	            // $message .= $c;
  	
			  	            mail($notify_emails, $subject, $message, 'From: '.$mail_from. "\r\n");
			  	          }
			  	        }
			  	      }
					  
						$query = "UPDATE ".$dbcrashtable." SET groupid=".$groupid." WHERE id=".$id;
						$result = mysql_query($query) or die(xml_for_result(FAILURE_SQL_ADD_CRASHLOG));
					  
					  if (!$result) {
						  $success = False;
					  }
			  	}
				
			} else {
				// We're already in a group
				$success = True;
			}
		}
	}
}

if ($success)
	echo "success";
else
	echo "error";

mysql_close($link);


?>