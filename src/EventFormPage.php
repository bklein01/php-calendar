<?php
/*
 * Copyright 2017 Sean Proctor
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace PhpCalendar;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class EventFormPage extends Page
{
    /**
     * Display event form or submit event
     *
     * @param  Context $context
     * @return Response
     */
    public function action(Context $context)
    {
        $form = $this->eventForm($context);

        $form->handleRequest($context->request);
        if ($form->isSubmitted() && $form->isValid()) {
            return $this->processForm($context, $form->getData());
        }
        
        // else
        return new Response($context->twig->render("event_create.html.twig", array('form' => $form->createView())));
    }

    /**
     * @param Context $context
     * @return Form
     */
    private function eventForm(Context $context)
    {
        $builder = $context->getFormFactory()->createBuilder();

        $builder->add(
            'subject',
            TextType::class,
            array('attr' => array('autocomplete' => 'off', 'maxlength' => $context->getCalendar()->getSubjectMax()),
                'label' => _('Subject'), 'constraints' => new Assert\NotBlank())
        )
        ->add('description', TextareaType::class, array('required' => false))
        ->add('start', DateTimeType::class, array('label' => __('From'), 'widget' => 'single_text'))
        ->add('end', DateTimeType::class, array('label' => __('To'), 'widget' => 'single_text'))
        ->add(
            'time_type',
            ChoiceType::class,
            array('label' => __('Time Type'),
                'choices' => array(
                    __('Normal') => 0,
                    __('Full Day') => 1,
                    __('To Be Announced') => 2))
        )
        ->add(
            'repeats',
            ChoiceType::class,
            array('label' => __('Repeats'),
                'choices' => array(
                    __('Never') => '0',
                    __('Daily') => 'D',
                    __('Weekly') => 'W',
                    __('Monthly') => 'M',
                    __('Yearly') => 'Y'))
        )
        ->add(
            'frequency',
            IntegerType::class,
            array('constraints' => new Assert\GreaterThan(0), 'data' => 1)
        )
        ->add('until', DateType::class, array('label' => __('Until'), 'widget' => 'single_text'));

        //echo "<pre>"; var_dump($context->request); echo "</pre>";
        if ($context->request->get('eid') !== null) {
            $eid = $context->request->get('eid');
            $event = $context->db->getEvent($eid);
            $occs = $context->db->get_occurrences_by_eid($eid);
            $occurrence = $occs[0];
            $builder->add(
                'modify',
                CheckboxType::class,
                array('label' => __('Change the event date and time'), 'required' => false)
            );
            $builder->add('eid', HiddenType::class, array('data' => $eid));
            $builder->get('subject')->setData($event->getRawSubject());
            $builder->get('description')->setData($event->getDescription());
            $builder->get('start')->setData($occurrence->getStart());
            $builder->get('end')->setData($occurrence->getEnd());
            $builder->add('save', SubmitType::class, array('label' => __('Modify Event')));
        } else {
            $builder->add('save', SubmitType::class, array('label' => __('Create Event')));
        }

        /*
        $calendar_choices = array();
        foreach($context->db->getCalendars() as $calendar) {
        if($calendar->canWrite($context->getUser()))
        $calendar_choices[$calendar->getTitle()] = $calendar->getCID();
        }
        
        if(sizeof($calendar_choices) > 1) {
        $builder->add('cid', ChoiceType::class, array('choices' => $calendar_choices));
        } else {
        $builder->add('cid', HiddenType::class, array('data' => $context->getCalendar()->getCID()));
        }*/

        $builder->addEventListener(
            FormEvents::POST_SUBMIT,
            function (FormEvent $event) {
                $form = $event->getForm();
                $data = $form->getData();
                if (!empty($data) && !empty($data['save']) && (empty($data['eid']) || $data['modify'])) {
                    if ($data['end']->getTimestamp() < $data['start']->getTimestamp()) {
                        $form->get('end')->addError(new FormError('The end date/time cannot be before the start date/time.'));
                    }
                }
            }
        );

        return $builder->getForm();
    }

    /**
     * @param Context $context
     * @param array   $data
     * @return Response
     */
    private function processForm(Context $context, $data)
    {
        // When modifying events, this is the value of the checkbox that
        //   determines if the date should change
        $modify_occur = !isset($data['eid']) || !empty($data['modify']);
    
        $calendar = $context->getCalendar();
        $user = $context->getUser();
    
        if (!$calendar->canWrite($user)) {
            permission_error(__('You do not have permission to write to this calendar.'));
        }
    
        $catid = empty($data['catid']) ? null : $data['catid'];
    
        if (!isset($data['eid'])) {
            $modify = false;
            $eid = $context->db->createEvent(
                $calendar->getCID(),
                $user->getUID(),
                $data["subject"],
                (string) $data["description"],
                $catid
            );
        } else {
            $modify = true;
            $eid = $data['eid'];
            $context->db->modifyEvent(
                $eid,
                $data['subject'],
                $data['description'],
                $catid
            );
            if ($modify_occur) {
                $context->db->delete_occurrences($eid);
            }
        }
    
        /*foreach($calendar->get_fields() as $field) {
        $fid = $field['fid'];
        if(empty($vars["phpc-field-$fid"])) {
        if($field['required'])
        throw new Exception(sprintf(__('Field "%s" is required but was not set.'), $field['name']));
        continue;
        }
        $phpcdb->add_event_field($eid, $fid, $vars["phpc-field-$fid"]);
        }*/
    
        if ($modify_occur) {
            $occurrences = 0;
            
            if ($data['repeats'] == '0') {
                $context->db->create_occurrence($eid, $data['time_type'], $data['start'], $data['end']);
            } else {
                $interval = new \DateInterval('P'.$data['frequency'].$data['repeats']);
                
                echo "days between: " . days_between($data['start'], $data['until']);

                while ($occurrences <= 730 && days_between($data['start'], $data['until']) >= 0) {
                    $oid = $context->db->create_occurrence($eid, $data['time_type'], $data['start'], $data['end']);
                    $occurrences++;
        
                    $data['start']->add($interval);
                    $data['end']->add($interval);
                }
            }
        }
    
        $context->addMessage(($modify ? __("Modified event") : __("Created event")).": $eid");
        return new RedirectResponse(action_event_url($context, 'display_event', $eid));
    }
}

function display_form()
{

    $categories = new FormDropdownQuestion('catid', __('Category'));
    $categories->add_option('', __('None'));
    $have_categories = false;
    foreach ($phpc_cal->get_visible_categories($phpc_user->get_uid()) as $category) {
        $categories->add_option($category['catid'], $category['name']);
        $have_categories = true;
    }
    if ($have_categories) {
        $form->add_part($categories);
    }

    foreach ($phpc_cal->get_fields() as $field) {
        $form->add_part(new FormFreeQuestion('phpc-field-'.$field['fid'], $field['name']));
    }

    if (isset($vars['eid'])) {
        $form->add_hidden('eid', $vars['eid']);
        $occs = $phpcdb->get_occurrences_by_eid($vars['eid']);
        $event = $occs[0];

        $defaults = array(
        'cid' => $event->get_cid(),
        'subject' => $event->get_raw_subject(),
        'description' => $event->get_raw_desc(),
        'start-date' => $event->get_short_start_date(),
        'end-date' => $event->get_short_end_date(),
        'start-time' => $event->get_start_time(),
        'end-time' => $event->get_end_time(),
        'readonly' => $event->is_readonly(),
        );

        foreach ($event->get_fields() as $field) {
            $defaults["phpc-field-{$field['fid']}"] = $field['value'];
        }

        if (!empty($event->catid)) {
            $defaults['catid'] = $event->catid;
        }

        switch ($event->get_time_type()) {
            case 0:
                $defaults['time-type'] = 'normal';
                break;
            case 1:
                $defaults['time-type'] = 'full';
                break;
            case 2:
                $defaults['time-type'] = 'tba';
                break;
        }

        add_repeat_defaults($occs, $defaults);
    } else {
        $hour24 = $phpc_cal->hours_24;
        $datefmt = $phpc_cal->date_format;
        $date_string = format_short_date_string($phpc_year, $phpc_month, $phpc_day, $datefmt);
        $defaults = array(
        'cid' => $phpcid,
        'start-date' => $date_string,
        'end-date' => $date_string,
        'start-time' => format_time_string(17, 0, $hour24),
        'end-time' => format_time_string(18, 0, $hour24),
        'daily-until-date' => $date_string,
        'weekly-until-date' => $date_string,
        'monthly-until-date' => $date_string,
        'yearly-until-date' => $date_string,
        );
    }
    return $form->get_form($defaults);
}

function add_repeat_defaults($occs, &$defaults)
{
    // TODO: Handle unevenly spaced occurrences

    $defaults['repeats'] = 'never';

    if (sizeof($occs) < 2) {
        return;
    }

    $event = $occs[0];
    $day = $event->get_start_day();
    $month = $event->get_start_month();
    $year = $event->get_start_year();

    // Test if they repeat every N years
    $nyears = $occs[1]->get_start_year() - $event->get_start_year();
    $repeats_yearly = true;
    $nmonths = ($occs[1]->get_start_year() - $year) * 12
    + $occs[1]->get_start_month() - $month;
    $repeats_monthly = true;
    $ndays = days_between($event->get_start_ts(), $occs[1]->get_start_ts());
    $repeats_daily = true;

    for ($i = 1; $i < sizeof($occs); $i++) {
        $cur_occ = $occs[$i];
        $cur_year = $cur_occ->get_start_year();
        $cur_month = $cur_occ->get_start_month();
        $cur_day = $cur_occ->get_start_day();

        // Check year
        $cur_nyears = $cur_year - $occs[$i - 1]->get_start_year();
        if ($cur_day != $day || $cur_month != $month
            || $cur_nyears != $nyears
        ) {
            $repeats_yearly = false;
        }

        // Check month
        $cur_nmonths = ($cur_year - $occs[$i - 1]->get_start_year())
        * 12 + $cur_month - $occs[$i - 1]->get_start_month();
        if ($cur_day != $day || $cur_nmonths != $nmonths) {
            $repeats_monthly = false;
        }

        // Check day
        $cur_ndays = days_between(
            $occs[$i - 1]->get_start_ts(),
            $occs[$i]->get_start_ts()
        );
        if ($cur_ndays != $ndays) {
            $repeats_daily = false;
        }
    }

    $defaults['yearly-until-date'] = "$cur_month/$cur_day/$cur_year";
    $defaults['monthly-until-date'] = "$cur_month/$cur_day/$cur_year";
    $defaults['weekly-until-date'] = "$cur_month/$cur_day/$cur_year";
    $defaults['daily-until-date'] = "$cur_month/$cur_day/$cur_year";

    if ($repeats_daily) {
        // repeats weekly
        if ($ndays % 7 == 0) {
            $defaults['repeats'] = 'weekly';
            $defaults['every-week'] = $ndays / 7;
        } else {
            $defaults['every-week'] = 1;

            // repeats daily
            $defaults['repeats'] = 'daily';
            $defaults['every-day'] = $ndays;
        }
    } else {
        $defaults['every-day'] = 1;
        $defaults['every-week'] = 1;
    }

    if ($repeats_monthly) {
        $defaults['repeats'] = 'monthly';
        $defaults['every-month'] = $nmonths;
    } else {
        $defaults['every-month'] = 1;
    }

    if ($repeats_yearly) {
        $defaults['repeats'] = 'yearly';
        $defaults['every-year'] = $nyears;
    } else {
        $defaults['every-year'] = 1;
    }
}
