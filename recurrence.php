<?php
/**
* PHP5 recurrence calculation / iCalendar RRULE format (RFC 5545)
* Author: Holger Münzer <holger.muenzer@gmail.com>
* Location: http://github.com/hmuenzer/recurrence
* Requirements: PHP 5.3
* Copyright (c) 2012 Holger Münzer
* Licensed under The MIT License
*
* Example usage:
  $options = array(
    'dtstart' => '19970902T090000',
    'dtend'   => '19970902T100000',
    'tzid'    => 'America/New_York',
    'rrule'   => 'FREQ=WEEKLY;UNTIL=19971007T000000Z;WKST=SU;BYDAY=TU,TH'
    );
  require_once('./recurrence.php');
  $recurrence = new recurrence($options);
  while($date = $recurrence->next()){
    print_r($date);
    if($z++ > 100) break;
    }
**/

 class recurrence {
   protected $dtstart;                                 //VEVENT parameters
   protected $dtend;
   protected $rdate           = array();
   protected $exdate          = array();
   protected $duration;
   protected $tzid;

   protected $freq;                                    //RRULE parameters
   protected $interval        = 1;
   protected $count;
   protected $until;
   protected $bymonth         = array();
   protected $byweekno        = array();
   protected $byyearday       = array();
   protected $bymonthday      = array();
   protected $byday           = array();
   protected $byhour          = array();
   protected $byminute        = array();
   protected $bysecond        = array();
   protected $bysetpos        = array();
   protected $wkst            = 'MO';

   protected $wkst_seq        = array('MO','TU','WE','TH','FR','SA','SU');

   protected $after;                                   //range to compute dates in
   protected $before;

   protected $expansions      = array();               //all expansions
   protected $limitations     = array();               //all limitations
   protected $expansion_count = 0;                     //number of expansions set by rule

   public    $error           = FALSE;                 //TRUE if error in properties found
   protected $timezone;                                //DateTimeZone object
   protected $datetime;                                //DateTime object
   protected $duration_time;                           //Duration in seconds
   protected $iteration       = 0;                     //number of interval repeats
   protected $current_count   = 0;                     //number of occurrence output
   protected $current_date;                            //last computed interval
   protected $cached_dates    = array();               //previously computed dates
   protected $cached_rdates   = array();               //dates from rdate rule
   protected $cached_details  = array();               //cached year, month and week details

   public    $format          = "Y-m-d H:i:s";         //output format
   public    $skip_not_in_range = FALSE;               //skip expanding of dates not in range to save time


   public function __construct($options){
     if(!$this->read_options($options)) return;
     $this->evaluate_options();
     $this->property_check();
     }

   protected function read_options($options = array()){

     if(!$options){                                                                //no options - abort
       $this->error = TRUE;
       return FALSE;
       }

     foreach($options as $key => $value)                                           //strtolower options
     $options_lower[strtolower($key)] = $value;
     $options = $options_lower;

     try {
       if($options['tzid']) $this->timezone = new DateTimeZone($options['tzid']);  //set timezone
       else $this->timezone = new DateTimeZone(date_default_timezone_get());
       }
     catch(Exception $e){
       $this->error = TRUE;
       return FALSE;
       }

     if($options['rrule']) foreach(explode(";", $options['rrule']) as $part){      //parse RRULE if present
       list($rule, $value) = explode("=", $part);
       $rule = strtolower($rule);
       if(property_exists($this, $rule) AND !array_key_exists($rule,$options))       //ignore unknown properties, manually options precede
       $options[$rule] = $value;
       }

     foreach($options as $option => $value){                                       //validate options
       if("" === (string)$value) continue;                                         //empty options will be ignored!!
       switch($option){
         case "dtstart":                                                           //DATETIME OR DATE values
         case "dtend":
         case "until":
         case "after":
         case "before":
           if(FALSE === ($this->$option = $this->strtotime($value)))                 //create timestamps
             $this->error = TRUE;
         break;
         case "rdate":                                                             //RDATE
           if(!is_array($value)) $value = array($value);
           foreach($value as $entry){
             $rdate = explode(",",$entry);
             foreach($rdate as $property){
               list($date, $period) = explode("/",$property);
               if(FALSE === ($start = $this->strtotime($date)))                      //create timestamp
               $this->error = TRUE;
               if($period){
                 try { $duration = new DateInterval($period); }                      //evaluate 2nd part as duration
                 catch(Exception $e){
                   $duration = NULL;
                   if(FALSE === ($end = $this->strtotime($period)))                  //evaluate 2nd part as datestring
                   $this->error = TRUE;
                   }
                 }
               $this->rdate[] = compact('start','end','duration');
               }
             }
         break;
         case "exdate":                                                            //EXDATE
           if(!is_array($value)) $value = array($value);
           foreach($value as $entry){
             $exdate = explode(",",$entry);
             foreach($exdate as $datestring){
               if(FALSE === ($this->exdate[] = $this->strtotime($datestring)))         //create timestamps
               $this->error = TRUE;
               }
             }
         break;
         case "duration":                                                          //DURATION
           try { $this->duration = new DateInterval($value); }
           catch(Exception $e){ $this->error = FALSE; }
         break;
         case "freq":                                                              //FREQUENCY
           $valid = array("SECONDLY","MINUTELY","HOURLY","DAILY","WEEKLY","MONTHLY","YEARLY");
           if(!in_array($this->freq = strtoupper($value), $valid))
           $this->error = TRUE;
         break;
         case "count":                                                             //NUMERIC VALUES
         case "interval":
           if(!is_numeric($this->$option = $value) OR $this->$option < 1)
           $this->error = TRUE;
         break;
         case "wkst":                                                              //WKST
           $valid = array("SU","MO","TU","WE","TH","FR","SA");
           if(!in_array($this->wkst = strtoupper($value), $valid))
           $this->error = TRUE;
         break;
         case "bymonth":                                                           //BYMONTH
           $this->bymonth = explode(",",$value);
           foreach($this->bymonth as $month)
           if(!is_numeric($month) OR $month < 1 OR $month > 12)
           $this->error = TRUE;
         break;
         case "byweekno":                                                          //BYWEEKNO
           $this->byweekno = explode(",",$value);
           foreach($this->byweekno as $week)
           if(!is_numeric($week) OR abs($week) < 1 OR abs($week) > 53)
           $this->error = TRUE;
         break;
         case "byyearday":                                                         //BYYEARDAY
         case "bysetpos":                                                          //BYSETPOS
           $this->$option = explode(",",$value);
           foreach($this->$option as $value)
           if(!is_numeric($value) OR abs($value) < 1 OR abs($value) > 366)
           $this->error = TRUE;
         break;
         case "bymonthday":                                                        //BYMONTHDAY
           $this->bymonthday = explode(",",$value);
           foreach($this->bymonthday as $day)
           if(!is_numeric($day) OR abs($day) < 1 OR abs($day) > 31)
           $this->error = TRUE;
         break;
         case "byday":                                                             //BYDAY
           $valid = array("SU","MO","TU","WE","TH","FR","SA");
           foreach(explode(",",$value) as $option){
             $weekday = substr($option,-2);
             $pos = substr($option,0,-2);
             $this->byday[] = compact('weekday','pos');
             if(!in_array($weekday, $valid) OR ($pos !== "" AND (!is_numeric($pos) OR $pos == 0)))
             $this->error = TRUE;
             }
         break;
         case "byhour":                                                            //BYHOUR
           $this->byhour = explode(",",$value);
           foreach($this->byhour as $hour)
           if(!is_numeric($hour) OR $hour < 0 OR $hour > 23)
           $this->error = TRUE;
         break;
         case "byminute":                                                          //BYMINUTE
         case "bysecond":                                                          //BYSECOND
           $this->$option = explode(",",$value);
           foreach($this->$option as $value)
           if(!is_numeric($value) OR $value < 0 OR $value > 59)
           $this->error = TRUE;
         break;
         }
       }
     if($this->error) return FALSE;
     return TRUE;
     }

   protected function evaluate_options(){
     if(NULL !== $this->until AND (NULL === $this->before OR $this->before > $this->until))      //synchronize until / before
       $this->before = $this->until;
     if(NULL !== $this->count OR NULL === $this->after)                                          //disable skipping
       $this->skip_not_in_range = FALSE;
     if(NULL !== $this->dtend){
       //calculate a nominal duration for anniversary or allday events instead of a exact duration
       //the value type of dtstart is not recognized by this script, so events starting and ending midnight are handeled as allday
       if(array("00","00","00") == $this->explode("H-i-s",$this->dtstart) AND array("00","00","00") == $this->explode("H-i-s",$this->dtend)){
         $number_of_days = round(($this->dtend - $this->dtstart)/86400);
         $this->duration = new DateInterval("P".$number_of_days."D");
         $this->dtend = NULL;
         }
       else $this->duration_time = $this->dtend - $this->dtstart;
       }
     if($this->rdate){
       foreach($this->rdate as $option) $this->cached_rdates[] = $option['start'];               //create rdate cache
       array_multisort($this->cached_rdates, SORT_NUMERIC, $this->rdate);                        //sort rdates
       if($this->exdate) $this->cached_rdates = array_diff($this->cached_rdates,$this->exdate);  //exclude exdates
       }

     $this->current_date = $this->dtstart;

     //define expansions to do and limitations to apply
     //conflicting rules are grouped, the results from grouped expansions will be intersected
     //example: BYYEARDAY=1,5,10;BYMONTHDAY=1,10 will be expanded to January 1, 10
     switch($this->freq){
       case "YEARLY":
         $this->expansions = array(array('bymonth'),array('byweekno'),array('byyearday','bymonthday','byday'),array('byhour'),array('byminute'),array('bysecond'));
         $this->limitations = array();
       break;
       case "MONTHLY":
         $this->expansions = array(array('bymonthday','byday'),array('byhour'),array('byminute'),array('bysecond'));
         $this->limitations = array('bymonth');
       break;
       case "WEEKLY":
         $this->expansions = array(array('byday'),array('byhour'),array('byminute'),array('bysecond'));
         $this->limitations = array('bymonth');
       break;
       case "DAILY":
         $this->expansions = array(array('byhour'),array('byminute'),array('bysecond'));
         $this->limitations = array('bymonth','bymonthday','byday');
       break;
       case "HOURLY":
         $this->expansions = array(array('byminute'),array('bysecond'));
         $this->limitations = array('bymonth','byyearday','bymonthday','byday','byhour');
       break;
       case "MINUTELY":
         $this->expansions = array(array('bysecond'));
         $this->limitations = array('bymonth','byyearday','bymonthday','byday','byhour','byminute');
       break;
       case "SECONDLY":
         $this->expansions = array();
         $this->limitations = array('bymonth','byyearday','bymonthday','byday','byhour','byminute','bysecond');
       break;
       }

     //count expansion to do
     foreach($this->expansions as $expansion_set) foreach($expansion_set as $expansion)
     if($this->$expansion) $this->expansion_count++;

     //set weekday order according to wkst
     while($this->wkst != $this->wkst_seq[0])
     $this->wkst_seq[] = array_shift($this->wkst_seq);
     }

   protected function property_check(){
     switch(TRUE){
       case(NULL === $this->dtstart):
       case(NULL !== $this->dtend AND $this->duration):
       case($this->count AND NULL !== $this->until):
       case(!$this->timezone):
       case(!$this->freq):
       case(NULL !== $this->duration_time AND 0 >= $this->duration_time):
       case($this->byweekno AND $this->freq != "YEARLY"):
       case($this->bymonthday AND $this->freq == "WEEKLY"):
       case($this->byyearday AND in_array($this->freq,array('DAILY','WEEKLY','MONTHLY'))):
       $this->error = TRUE;
       }
     }

   public function next(){
     if($this->error) return FALSE;                                                 //property error: abort
     if($this->count AND $this->current_count >= $this->count) return FALSE;        //max count reached: abort
     if($this->cached_dates) return $this->output();                                //return cached dates first
     if($this->before AND $this->current_date >= $this->before){                    //interval out of range
       if($this->cached_rdates) return $this->output();                               //rdates left: output
       return FALSE;                                                                  //abort
       }

     do {
       $this->iteration++;
       if(++$safety_brake > 1000) break;                                              //fail safe: abort after 1000 failed iterations

       if(1 < $this->iteration) $this->next_interval();                               //apply interval from 2nd iteration on (dtstart is first date to be expanded)
       if($this->out_of_range()) return FALSE;                                        //current date out of range: abort
       if($this->skip_not_in_range AND $this->not_in_range()) continue;               //current date not in range: next interval

       $dates = array($this->current_date);                                           //start expanding with current date

       if(!$this->expansion_count){                                                   //no EXPANSIONS, apply LIMITATIONS only
         if($this->exdate AND in_array($this->current_date, $this->exdate)) continue;   //restricted by exdate: next interval
         foreach($this->limitations as $limitation)
         if($this->$limitation AND FALSE == $this->{'limit_'.$limitation}($this->current_date)) continue 2;  //restricted by rule: next interval
         }

       else {
         foreach($this->expansions as $expansion_set){                                //EXPANSIONS
           $expanded_dates = array();
           foreach($dates as $date){
             $result_dates = array();
             foreach($expansion_set as $expansion)                                      //compute dates for each expansion in set
             if($this->$expansion) $result_dates[] = $this->{'expand_'.$expansion}($date);
             if(!$result_dates) continue 2;                                             //no expansions done: continue with next set
             $this->merge_set(& $expanded_dates,$result_dates);                         //merge result
             }
           if(!$dates = $expanded_dates) continue 2;                                    //no result: next interval
           }
         $dates = array_unique($dates);                                                 //remove doubles

         $limited_dates = array();
         foreach($dates as $date){                                                    //LIMITATIONS
           foreach($this->limitations as $limitation)
           if($this->$limitation AND FALSE == $this->{'limit_'.$limitation}($date)) continue 2;  //restricted by rule: continue with next date
           $limited_dates[] = $date;
           }
         if($this->bysetpos) $this->limit_bysetpos(& $limited_dates);                   //apply bysetpos
         if($this->exdate) $limited_dates = array_diff($limited_dates,$this->exdate);   //apply exdate
         if(!$dates = $limited_dates) continue;                                         //no result: next interval

         $limited_dates = array();
         foreach($dates as $date){
           if(NULL !== $this->before AND $date > $this->before) continue;               //check range
           if($date < $this->dtstart) continue;
           $limited_dates[] = $date;
           }
         if(!$dates = $limited_dates) continue;                                         //no result: next interval
         }

       $this->cached_dates = $dates;                                                  //CACHE dates
       return $this->output();                                                        //output values
       } while(1);
     if($this->cached_rdates) return $this->output();                                 //iteration stopped, but rdates left
     return FALSE;
     }

   protected function output(){

     if(0 == $this->current_count AND                                            //if dtstart is not synchronized with the rrule
        $this->dtstart != $this->cached_dates[0] AND                             //as recommended by the RFC, dtstart may be dropped before
        $this->dtstart != current($this->cached_rdates) AND                      //if so it will be returned nevertheless
        !in_array($this->dtstart,$this->exdate)){

       $start =& $this->dtstart;
       $end =& $this->dtend;
       $duration =& $this->duration;
       }

     elseif($this->cached_rdates AND                                             //output RDATE
            (!$this->cached_dates OR
            current($this->cached_rdates) <= $this->cached_dates[0])){

       list($key, $start) = each($this->cached_rdates);
       $end =& $this->rdate[$key]['end'];
       $duration =& $this->rdate[$key]['duration'];
       $duration_time =& $this->duration_time;
       if($start == $this->cached_dates[0]) array_shift($this->cached_dates);      //remove duplicate entry
       }

     elseif(NULL !== ($start = array_shift($this->cached_dates))){               //output CACHED DATE
       $duration =& $this->duration;
       $duration_time =& $this->duration_time;
       }

     else return $this->next();                                                  //no values left: next interval

     if(NULL !== $this->after AND $start < $this->after){
        $this->current_count++;
        return $this->output();                                                  //not in range: next output
        }

     $output['dtstart'] = $this->date($this->format,$start);                     //create output

     if($duration){
       $this->datetime = new DateTime("@".$start);
       $this->datetime->setTimezone($this->timezone);
       $this->datetime->add($duration);
       $output['dtend'] = $this->datetime->format($this->format);
       }

     elseif(NULL !== $end)
       $output['dtend'] = $this->date($this->format,$end);

     elseif($duration_time)
       $output['dtend'] = $this->date($this->format,$start+$duration_time);

     $output['recurrence-id'] = gmdate("Ymd\THis\Z",$start);

     if(!$this->skip_not_in_range AND $this->current_count > 0)
       $output['x-recurrence'] = $this->current_count;

     if(isset($key)) unset($this->cached_rdates[$key]);                          //remove used rdate from cache
     $this->current_count++;
     return $output;
     }

   protected function next_interval(){
     list($Y,$m,$d,$H,$i,$s) = $this->explode("Y-m-d-H-i-s",$this->current_date);
     switch($this->freq){
       case "YEARLY":   $this->current_date = $this->mktime($H,$i,$s,$m,$d,$Y+$this->interval);   break;
       case "MONTHLY":
         if($d <= 28) $this->current_date = $this->mktime($H,$i,$s,$m+$this->interval,$d,$Y);     //default: take day part from dtstart
         elseif($this->bymonthday OR $this->byday)                                                //day part is defined by rule:
           $this->current_date = $this->mktime($H,$i,$s,$m+$this->interval,28,$Y);                  //move day to a safe position
         else {
           $n = 1;
           while($d > $this->date("t",$this->mktime(0,0,0,$m + $this->interval * $n,1,$Y))){      //skip months with fewer days
             $n++; $this->iteration++;
             }
           $this->current_date = $this->mktime($H,$i,$s,$m + $this->interval * $n,$d,$Y);
           }
       break;
       case "WEEKLY":   $this->current_date = $this->mktime($H,$i,$s,$m,$d+$this->interval*7,$Y); break;
       case "DAILY":    $this->current_date = $this->mktime($H,$i,$s,$m,$d+$this->interval,$Y);   break;
       case "HOURLY":   $this->current_date = $this->mktime($H+$this->interval,$i,$s,$m,$d,$Y);   break;
       case "MINUTELY": $this->current_date = $this->mktime($H,$i+$this->interval,$s,$m,$d,$Y);   break;
       case "SECONDLY": $this->current_date = $this->mktime($H,$i,$s+$this->interval,$m,$d,$Y);   break;
       }
     }

   protected function out_of_range(){
     if(NULL === $this->before) return FALSE;
     if(!$this->expansion_count AND $this->current_date > $this->before) return TRUE;             //no expansions: check current date only

     switch($this->freq){                                                                         //check range of possible expansions
       case "YEARLY":
         $year_details = $this->year_details($this->date("Y",$this->current_date));
         if($this->byweekno AND $year_details['week_start'] < $year_details['start']) $start = $year_details['week_start'];
         else $start = $year_details['start'];
         if($start > $this->before) return TRUE;
       break;
       case "MONTHLY":
         list($Y,$m) = $this->explode("Y-m",$this->current_date);
         $month_details = $this->month_details($m,$Y);
         if($month_details['start'] > $this->before) return TRUE;
       break;
       case "WEEKLY":
         list($Y,$z) = $this->explode("Y-z",$this->current_date);
         $week_details = $this->week_details($z,$Y);
         if($week_details['start'] > $this->before) return TRUE;
       break;
       case "DAILY":
         list($Y,$m,$d) = $this->explode("Y-m-d",$this->current_date);
         $day_start = $this->mktime(0,0,0,$m,$d,$Y);
         if($day_start > $this->before) return TRUE;
       break;
       case "HOURLY":
         list($Y,$m,$d,$H) = $this->explode("Y-m-d-H",$this->current_date);
         $hour_start = $this->mktime($H,0,0,$m,$d,$Y);
         if($hour_start > $this->before) return TRUE;
       break;
       case "MINUTELY":
         list($Y,$m,$d,$H,$i) = $this->explode("Y-m-d-H-i",$this->current_date);
         $minute_start = $this->mktime($H,$i,0,$m,$d,$Y);
         if($minute_start > $this->before) return TRUE;
       break;
       case "SECONDLY":
         if($this->current_date > $this->before) return TRUE;
       break;
       }
     return FALSE;
     }

   protected function not_in_range(){
     if(NULL === $this->after) return FALSE;
     if(!$this->expansion_count AND $this->current_date < $this->after) return TRUE;           //no expansions: check current date only

     switch($this->freq){                                                                      //check range of possible expansions
       case "YEARLY":
         $year_details = $this->year_details($this->date("Y",$this->current_date));
         if($this->byweekno AND $year_details['week_end'] > $year_details['end']) $end = $year_details['week_end'];
         else $end = $year_details['end'];
         if($end < $this->after) return TRUE;
       break;
       case "MONTHLY":
         list($Y,$m) = $this->explode("Y-m",$this->current_date);
         $month_details = $this->month_details($m,$Y);
         if($month_details['end'] < $this->after) return TRUE;
       break;
       case "WEEKLY":
         list($Y,$z) = $this->explode("Y-z",$this->current_date);
         $week_details = $this->week_details($z,$Y);
         if($week_details['end'] < $this->after) return TRUE;
       break;
       case "DAILY":
         list($Y,$m,$d) = $this->explode("Y-m-d",$this->current_date);
         $day_end = $this->mktime(23,59,59,$m,$d,$Y);
         if($day_end < $this->after) return TRUE;
       break;
       case "HOURLY":
         list($Y,$m,$d,$H) = $this->explode("Y-m-d-H",$this->current_date);
         $hour_end = $this->mktime($H,59,59,$m,$d,$Y);
         if($hour_end < $this->after) return TRUE;
       break;
       case "MINUTELY":
         list($Y,$m,$d,$H,$i) = $this->explode("Y-m-d-H-i",$this->current_date);
         $minute_end = $this->mktime($H,$i,59,$m,$d,$Y);
         if($minute_end < $this->after) return TRUE;
       break;
       case "SECONDLY":
         if($this->current_date < $this->after) return TRUE;
       break;
       }
     return FALSE;
     }

   protected function merge_set($expanded_dates,$set){
     if(!$set){ $expanded_dates = array(); return; }
     $merge = $set[0];
     for($i = 1; $i < count($set); $i++)
     $merge = array_intersect($merge,$set[$i]);
     $expanded_dates = array_merge($expanded_dates,$merge);
     }

   protected function expand_bymonth($date){
     $dates = array();
     list($Y,$d,$H,$i,$s,$L) = $this->explode("Y-d-H-i-s-L",$date);
     $limit = array('',31,$L?29:28,31,30,31,30,31,31,30,31,30,31);                                //last day for each month
     if($this->byweekno OR $this->byyearday OR $this->bymonthday OR $this->byday) $shift = TRUE;  //day part is defined by rule: shifting allowed
     foreach($this->bymonth as $month){                                                           //create one date for each MONTH
       if($d > $limit[$month]){                                                                   //out of range
         if($shift) $d = $limit[$month];                                                            //shift day
         else continue;                                                                             //skip month
         }
       $dates[] = $this->mktime($H,$i,$s,$month,$d,$Y);
       }
     sort($dates,SORT_NUMERIC);
     return $dates;
     }

   protected function expand_byweekno($date){
     $dates = array();
     list($Y,$m,$H,$i,$s) = $this->explode("Y-m-H-i-s",$date);

     $year[$Y] = $this->year_details($Y);                                            //results are limited to current year
     $start = $year[$Y]['start'];
     $end = $year[$Y]['end'];

     if($this->bymonth){                                                             //previous applied BYMONTH limits the result
       $start = $this->mktime(0,0,0,$m,1,$Y);
       $end = $this->mktime(0,0,0,$m+1,1,$Y)-1;
       }

     if($year[$Y]['week_end'] < $end)                                                //check results from overlapping years too
       $year[$Y+1] = $this->year_details($Y+1);
     elseif($year[$Y]['week_start'] > $start)
       $year[$Y-1] = $this->year_details($Y-1);

     if(!$this->byyearday AND !$this->bymonthday AND !$this->byday){                 //day part NOT defined by rule: take weekday from dtstart
       $days = array('SU','MO','TU','WE','TH','FR','SA');
       $weekday = $days[$this->date("w",$this->dtstart)];
       $pos_weekday = array_search($weekday,$this->wkst_seq);                          //position of the weekday (depends on wkst)
       }

     foreach($year as $Y => $year_details){
       foreach($this->byweekno as $week){                                              //create one date for each WEEK
         if(abs($week) > $year_details['number_of_weeks']) continue;                     //week number out of range: skip week
         if($week < 0) $week = $year_details['number_of_weeks'] + $week + 1;
         if(isset($pos_weekday)){                                                        //weekday is specified by dtstart: test weekday only
           $test_date = $this->mktime($H,$i,$s,1,1 + $year_details['week_offset'] + $pos_weekday + ($week-1)*7,$Y);
           if($test_date < $start) continue;                                             //date out of range: skip week
           if($test_date > $end) continue;
           }
         else {                                                                          //weekday is expanded later, find one valid day of the week
           $pos = 0;
           do {
             if($pos > 6) continue 2;                                                      //out of range: skip week
             $test_date = $this->mktime($H,$i,$s,1,1 + $year_details['week_offset'] + $pos + ($week-1)*7,$Y);
             if($test_date >= $start AND $test_date <= $end) break;
             $pos++;
             } while(1);
           }
         $dates[] = $test_date;
         }
       }

     sort($dates,SORT_NUMERIC);
     return $dates;
     }

   protected function expand_byyearday($date){
     $dates = array();
     list($Y,$m,$z,$H,$i,$s,$L) = $this->explode("Y-m-z-H-i-s-L",$date);
     $number_of_days = $L ? 366 : 365;

     $start = $this->mktime(0,0,0,1,1,$Y);                                                   //results are limited to current year
     $end = $this->mktime(23,59,59,12,31,$Y);

     if($this->bymonth){                                                                     //previous applied BYMONTH limits the result
       $month_details = $this->month_details($m,$Y);
       $start = $month_details['start'];
       $end = $month_details['end'];
       }

     if($this->byweekno){                                                                    //previous applied BYWEEKNO limits the result
       $week_details = $this->week_details($z,$Y);
       if($start < $week_details['start']) $start = $week_details['start'];
       if($end > $week_details['end']) $end = $week_details['end'];
       }

     foreach($this->byyearday as $day){                                                      //create one date for each YEARDAY
       if($day < 0) $day = $number_of_days + $day +1;
       $date = $this->mktime($H,$i,$s,1,$day,$Y);
       if($date < $start) continue;                                                            //out of range: skip day
       if($date > $end) continue;
       $dates[] = $date;
       }

     sort($dates,SORT_NUMERIC);                                                              //sort dates
     return $dates;
     }

   protected function expand_bymonthday($date){
     $dates = array();
     list($Y,$m,$z,$H,$i,$s) = $this->explode("Y-m-z-H-i-s",$date);
     $month[$m] = $this->month_details($m,$Y);

     $start = $this->mktime(0,0,0,1,1,$Y);                                                   //results are limited to current year
     $end = $this->mktime(23,59,59,12,31,$Y);

     if($this->bymonth){                                                                     //previous applied BYMONTH limits the result
       $start = $month[$m]['start'];
       $end = $month[$m]['end'];
       }

     if($this->byweekno){                                                                    //previous applied BYWEEKNO limits the result
       $week_details = $this->week_details($z,$Y);
       if($start < $week_details['start']) $start = $week_details['start'];
       if($end > $week_details['end']) $end = $week_details['end'];
       if(!$this->bymonth){                                                                    //if no BYMONTH specified, consider overlapping months too
         if($month[$m]['end'] < $end)
           $month[$m+1] = $this->month_details($m+1,$Y);
         elseif($month[$m]['start'] > $start)
           $month[$m-1] = $this->month_details($m-1,$Y);
         }
       }

     foreach($month as $m => $month_details){
       foreach($this->bymonthday as $day){                                                     //create one date for each MONTHDAY
         if(abs($day) > $month_details['number_of_days']) continue;                              //out of range: skip day
         if($day < 0) $day = $month_details['number_of_days'] + $day +1;
         $date = $this->mktime($H,$i,$s,$m,$day,$Y);
         if($date < $start) continue;                                                            //out of range: skip day
         if($date > $end) continue;
         $dates[] = $date;
         }
       }

     sort($dates,SORT_NUMERIC);                                                              //sort dates
     return $dates;
     }

   protected function expand_byday($date){
     $dates = array();
     if("WEEKLY" == $this->freq OR $this->byweekno){                                         //special expand for WEEKLY
       list($Y,$m,$z,$H,$i,$s) = $this->explode("Y-m-z-H-i-s",$date);
       if($this->bymonth){                                                                     //previous applied BYMONTH limits the result
         $month_details = $this->month_details($m,$Y);
         $start = $month_details['start'];
         $end = $month_details['end'];
         }
       $week_details = $this->week_details($z,$Y);
       list($Y,$m,$d) = $this->explode("Y-m-d",$week_details['start']);
       foreach($this->byday as $option){                                                       //apply BYDAY
         if($option['pos']) continue;                                                            //position & WEEKLY is invalid: skip day
         $pos = array_search($option['weekday'],$this->wkst_seq);                                //position of day from week start
         $date = $this->mktime($H,$i,$s,$m,$d + $pos,$Y);
         if(isset($start) AND $date < $start) continue;                                          //out of range: skip day
         if(isset($end) AND $date > $end) continue;
         $dates[] = $date;
         }
       sort($dates,SORT_NUMERIC);                                                              //sort dates
       return $dates;
       }
     if("MONTHLY" == $this->freq OR $this->bymonth){                                         //special expand for MONTHLY
       list($Y,$m,$H,$i,$s) = $this->explode("Y-m-H-i-s",$date);
       $month_details = $this->month_details($m,$Y);
       foreach($this->byday as $option){                                                       //apply BYDAY
         $pos = array_search($option['weekday'],$month_details['start_seq']);                  //position of day from month start
         $all_days = array();
         while($pos < $month_details['number_of_days']){
           $all_days[] = $this->mktime($H,$i,$s,$m,$pos+1,$Y);
           $pos += 7;
           }
         if(!$all_days) continue;
         if(!$option['pos']){                                                                  //no position - merge all days
           $dates = array_merge($dates, $all_days);
           continue;
           }
         if(abs($option['pos']) > count($all_days)) continue;                                  //position out of range
         if($option['pos'] < 0) $dates[] = $all_days[count($all_days) + $option['pos']];       //merge day by position
         else $dates[] = $all_days[$option['pos'] - 1];
         }
       sort($dates,SORT_NUMERIC);                                                              //sort dates
       return $dates;
       }
     if("YEARLY" == $this->freq){                                                            //special expand for YEARLY
       list($Y,$H,$i,$s) = $this->explode("Y-H-i-s",$date);
       $year_details = $this->year_details($Y);
       foreach($this->byday as $option){                                                       //apply BYDAY
         $pos = array_search($option['weekday'],$year_details['start_seq']);                   //position of day from year start
         $all_days = array();
         while($pos < $year_details['number_of_days']){
           $all_days[] = $this->mktime($H,$i,$s,1,$pos+1,$Y);
           $pos += 7;
           }
         if(!$all_days) continue;
         if(!$option['pos']){                                                                  //no position - merge all days
           $dates = array_merge($dates, $all_days);
           continue;
           }
         if(abs($option['pos']) > count($all_days)) continue;                                  //position out of range
         if($option['pos'] < 0) $dates[] = $all_days[count($all_days) + $option['pos']];       //merge day by position
         else $dates[] = $all_days[$option['pos'] - 1];
         }
       sort($dates,SORT_NUMERIC);                                                              //sort dates
       return $dates;
       }
     }

   protected function expand_byhour($date){
     $dates = array();
     list($Y,$m,$d,$i,$s) = $this->explode("Y-m-d-i-s",$date);
     foreach($this->byhour as $hour)
     $dates[] = $this->mktime($hour,$i,$s,$m,$d,$Y);
     sort($dates,SORT_NUMERIC);
     return $dates;
     }

   protected function expand_byminute($date){
     $dates = array();
     list($Y,$m,$d,$H,$s) = $this->explode("Y-m-d-H-s",$date);
     foreach($this->byminute as $minute)
     $dates[] = $this->mktime($H,$minute,$s,$m,$d,$Y);
     sort($dates,SORT_NUMERIC);
     return $dates;
     }

   protected function expand_bysecond($date){
     $dates = array();
     list($Y,$m,$d,$H,$i) = $this->explode("Y-m-d-H-i",$date);
     foreach($this->bysecond as $second)
     $dates[] = $this->mktime($H,$i,$second,$m,$d,$Y);
     sort($dates,SORT_NUMERIC);
     return $dates;
     }

   protected function limit_bymonth($date){
     if(in_array($this->date("n",$date), $this->bymonth)) return TRUE;
     return FALSE;
     }

   protected function limit_byyearday($date){
     list($z,$L) = $this->explode("z-L",$date);
     if(in_array($z+1, $this->byyearday)) return TRUE;
     if(in_array($z - ($L ? 366 : 365), $this->byyearday)) return TRUE;
     return FALSE;
     }

   protected function limit_bymonthday($date){
     list($j,$t) = $this->explode("j-t",$date);
     if(in_array($j, $this->bymonthday)) return TRUE;
     if(in_array($j - $t - 1, $this->bymonthday)) return TRUE;
     return FALSE;
     }

   protected function limit_byday($date){
     foreach($this->byday as $option)                                          //recombine position & weekday
     $byday[] = $option['pos'].$option['weekday'];
     $days = array('SU','MO','TU','WE','TH','FR','SA');
     list($w,$j,$z,$t,$L) = $this->explode("w-j-z-t-L",$date);
     if(in_array($days[$w], $byday)) return TRUE;                              //check weekday without position
     if($this->bymonth){                                                       //previous applied BYMONTH: check position relative to month
       if(in_array(ceil($j/7).$days[$w], $byday)) return TRUE;
       if(in_array((ceil(($t-$j+1)/7)*-1).$days[$w], $byday)) return TRUE;
       }
     else {                                                                    //check position relative to year
       $number_of_days = $L ? 366 : 365;
       if(in_array(ceil(($z+1)/7).$days[$w], $byday)) return TRUE;
       if(in_array((ceil(($number_of_days-$z)/7)*-1).$days[$w], $byday)) return TRUE;
       }
     return FALSE;
     }

   protected function limit_byhour($date){
     if(in_array($this->date("G",$date), $this->byhour)) return TRUE;
     return FALSE;
     }

   protected function limit_byminute($date){
     if(in_array((int)$this->date("i",$date), $this->byminute)) return TRUE;
     return FALSE;
     }

   protected function limit_bysetpos($limited_dates){
     $num = count($limited_dates);
     foreach($this->bysetpos as $pos){
       if($pos < 0) $pos = $num + $pos +1;
       $result[] = $limited_dates[$pos-1];
       }
     $limited_dates = $result;
     }

   protected function week_details($day,$year){
     if($this->cached_details['week'][$year][$day])                                           //return cached values
       return $this->cached_details['week'][$year][$day];
     $year_details = $this->year_details($year);
     $week_diff = floor(($day - $year_details['week_offset'])/7);                             //difference to 1st week in year
     $start = $this->mktime(0,0,0,1,1 + $year_details['week_offset'] + $week_diff*7,$year);
     $end = $this->mktime(23,59,59,1,1 + $year_details['week_offset'] + $week_diff*7 + 6,$year);
     return $this->cached_details['week'][$year][$day] = compact(array('start','end'));
     }

   protected function month_details($month,$year){
     if($this->cached_details['month'][$year][$month])                                        //return cached values
       return $this->cached_details['month'][$year][$month];
     $start = $this->mktime(0,0,0,$month,1,$year);
     list($number_of_days,$w) = $this->explode("t-w",$start);
     $end = $this->mktime(23,59,59,$month,$number_of_days,$year);
     $start_seq = array('SU','MO','TU','WE','TH','FR','SA');                                  //weekday order at month start / end
     $first_weekday = $start_seq[$w];
     while($first_weekday != $start_seq[0])
     $start_seq[] = array_shift($start_seq);
     return $this->cached_details['month'][$year][$month] = compact(array('start','end','start_seq','number_of_days'));
     }

   protected function year_details($year){
     if($this->cached_details['year'][$year])                                              //return cached values
       return $this->cached_details['year'][$year];
     $start = $this->mktime(0,0,0,1,1,$year);
     $end = $this->mktime(23,59,59,12,31,$year);
     list($L,$w) = $this->explode("L-w",$start);
     $number_of_days = $L ? 366 : 365;
     $start_seq = $end_seq = array('SU','MO','TU','WE','TH','FR','SA');                    //weekday order at year start / end
     $first_weekday = $start_seq[$w];
     $last_weekday = $start_seq[$this->date("w",$end)];
     while($first_weekday != $start_seq[0])
     $start_seq[] = array_shift($start_seq);
     while($last_weekday != $end_seq[6])
     $end_seq[] = array_shift($end_seq);
     if(($pos = array_search($this->wkst,$start_seq)) < 4) $week_offset = $pos;            //week offset (negative = 1st week begins december)
     else $week_offset = $pos - 7;
     if(($pos = array_search($this->wkst,$end_seq)) < 4) $last_week_offset = 0 - $pos;     //last week offset (negative = last week ends january)
     else $last_week_offset = 7 - $pos;
     $number_of_weeks = round(($number_of_days - $week_offset - $last_week_offset) / 7);   //number of calendar weeks
     $week_start = $this->mktime(0,0,0,1,1 + $week_offset,$year);                          //start of first week
     $week_end = $this->mktime(23,59,59,12,31 - $last_week_offset,$year);                  //end of last week
     return $this->cached_details['year'][$year] = compact(array('start','end','start_seq','number_of_days','number_of_weeks','week_offset','week_start','week_end'));
     }

   protected function mktime($hour, $min, $sec, $mon, $day, $year){
     if(!$this->datetime instanceof DateTime)
       $this->datetime = new DateTime(NULL, $this->timezone);
     try {
       $this->datetime->setDate($year, $mon, $day);
       $this->datetime->setTime($hour, $min, $sec);
       }
     catch(Exception $e) { return FALSE; }
     return $this->datetime->format("U");
     }

   protected function strtotime($time){
     try { $this->datetime = new DateTime($time, $this->timezone); }
     catch(Exception $e) { return FALSE; }
     return $this->datetime->format("U");
     }

   protected function explode($format, $timestamp){
     try { $this->datetime = new DateTime('@'.$timestamp); }
     catch(Exception $e) { return FALSE; }
     $this->datetime->setTimezone($this->timezone);
     return explode("-",$this->datetime->format($format));
     }

   protected function date($format, $timestamp){
     try { $this->datetime = new DateTime('@'.$timestamp); }
     catch(Exception $e) { return FALSE; }
     $this->datetime->setTimezone($this->timezone);
     return $this->datetime->format($format);
     }

   }

?>