## Why is this here?

This PHP class is a small part of a closed source PHP project of mine.

I use open source at a regular basis, so it is time for me to give something in return.

There are some PHP based scripts out here, that do the calculation as well, but either they are incomplete or they are pretty complex (and incomplete too).

If you only need the calculation, this might be of help.

So, here it is.

## How to use this

    <?php

        use \hmuenzer\Recurrence;
        require_once('./src/Recurrence.php');

        $options = array(
          'dtstart' => '19970902T090000',
          'dtend'   => '19970902T100000',
          'tzid'    => 'America/New_York',
          'rrule'   => 'FREQ=WEEKLY;UNTIL=19971007T000000Z;WKST=SU;BYDAY=TU,TH'
          );

        $recurrence = new Recurrence($options);
        $recurrence->format = 'Ymd\THisO';

        while($date = $recurrence->next()){
          print_r($date);
          if($z++ > 100) break;
          }

    ?>

## Feature List

This class supports all recurrence properties as defined by RFC 5545 for a single "VEVENT", "VTODO" or "VJOURNAL" calendar component:

  * EXDATE, RDATE and RRULE
  * DTSTART, DTEND and DURATION
  * Frequeny: SECONDLY to YEARLY
  * INTERVAL, COUNT and UNTIL
  * All BYxxx rule parts and all possible combinations (even the maniac ones)
  * WKST and TZID (Weeknumbering like specified by RFC 5545)
  * Recurring anniversary / allday events

Not supported are exceptions defined by RECURRENCE-ID because this needs parsing the whole ical-file and this class is not capable of this.
Timezone calculation is done with timezone informations supported by PHP, not with the ones defined by VTIMEZONE calendar components.

## More examples

    <?php

        //use of duration, rdate and exdate
        $options = array(
            'dtstart'  => '20121125T140000',
            'duration' => 'PT1H',
            'tzid'     => 'Europe/Berlin',
            'rrule'    => 'FREQ=YEARLY;BYMONTH=1,3,5,7,9,11;BYDAY=-1SU;COUNT=10',
            'rdate'    => '20130127T140000/20121125T143000,20130224T140000/PT3H',
            'exdate'   => '20130929T140000,20130728T140000'
          );

        //define rule parts separately, allday events and specify a range
        $options = array(
            'dtstart'  => '19671224',
            'dtend'    => '19671225',
            'tzid'     => 'Europe/Berlin',
            'freq'     => 'YEARLY',
            'after'    => '20120101T000000Z',
            'before'   => '20130101T000000Z'
          );

        //the class calculates a value "x-reccurence"
        //if you don't need this, you can do "skip not in range" to safe computing time
        //this property is ignored if a COUNT rule is specified
        //you can specify the output format
        //skip_not_in_range and format can not be passed to the constructor

        $options = array(
            'dtstart'  => '20120131T150000',
            'dtend'    => '20120131T153000',
            'tzid'     => 'Europe/Berlin',
            'rrule'    => 'FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1',
            'after'    => '20120101T000000Z',
            'before'   => '20130101T000000Z'
          );

        $recurrence = new Recurrence($options);
        $recurrence->format = 'Ymd\THis';
        $recurrence->skipNotInRange = TRUE;

        //multiple separate rdate and exdate rules

        $options['dtstart'] = '20121125T140000';
        $options['duration'] = 'PT1H';
        $options['tzid'] = 'Europe/Berlin';
        $options['rrule'] = 'FREQ=YEARLY;BYMONTH=1,3,5,7,9,11;BYDAY=-1SU;COUNT=10';
        $options['rdate'][] = '20130127T140000';
        $options['rdate'][] = '20130224T140000';
        $options['exdate'][] = '20130929T140000';
        $options['exdate'][] = '20130728T140000';

        //error handling

        $options = array(
            'dtstart'  => '20120131T150000',
            'rrule'    => 'FREQ=MONTHLY;BYWEEKNO=1,2,3'  //invalid RRULE
          );

        $recurrence = new Recurrence($options);
        if($recurrence->error){
          //error routine here
          }

    ?>

## Some crazy rules

    <?php

        //every 29th december that is calendar week one
        $options = array(
            'dtstart'  => '20031229T150000',
            'rrule'    => 'FREQ=YEARLY;BYMONTH=12;BYMONTHDAY=29;BYWEEKNO=1'
              OR
            'rrule'    => 'FREQ=YEARLY;BYYEARDAY=-3;BYWEEKNO=1'
          );

        //every 3rd january that is in the last calendar week of the previous year
        $options = array(
            'dtstart'  => '20100103T150000',
            'rrule'    => 'FREQ=YEARLY;BYMONTH=1;BYMONTHDAY=3;BYWEEKNO=-1'
              OR
            'rrule'    => 'FREQ=YEARLY;BYYEARDAY=3;BYWEEKNO=-1'
          );

        //every 31th that is a Friday
        $options = array(
            'dtstart'  => '20000131T090000',
            'rrule'    => 'FREQ=MONTHLY;BYMONTHDAY=31;BYDAY=FR'
          );

    ?>
