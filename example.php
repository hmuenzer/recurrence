<?php

        $options = array(
          'dtstart' => '19970902T090000',
          'dtend'   => '19970902T100000',
          'tzid'    => 'America/New_York',
          'rrule'   => 'FREQ=WEEKLY;UNTIL=19971007T000000Z;WKST=SU;BYDAY=TU,TH'
          );

        require_once('./Recurrence.php');
        $recurrence = new Recurrence($options);
        $recurrence->format = 'Ymd\THisO';

        while($date = $recurrence->next()){
          print_r($date);
          if($z++ > 100) break;
          }

?>