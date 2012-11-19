<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Defines renderer for course format flexsections
 *
 * @package    format_flexsections
 * @copyright  2012 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot.'/course/format/renderer.php');

/**
 * Basic renderer for topics format.
 *
 * @copyright 2012 Marina Glancy
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class format_flexsections_renderer extends plugin_renderer_base {

    /**
     * Renders course header/footer
     *
     * @param renderable $obj
     * @return string
     */
    public function render_format_flexsections_courseobj($obj) {
        return html_writer::tag('div', "<b>{$obj->text}</b>",
                array('style' => 'background: #'.$obj->background.'; border: 1px solid black; text-align: center; padding: 5px;'));
    }

    /**
     * Generate the section title (with link if section is collapsed)
     *
     * @param int|section_info $section
     * @param stdClass $course The course entry from DB
     * @return string HTML to output.
     */
    public function section_title($section, $course, $supresslink = false) {
        $title = get_section_name($course, $section);
        if (!$supresslink) {
            $url = course_get_url($course, $section, array('navigation' => true));
            if ($url) {
                $title = html_writer::link($url, $title);
            }
        }
        return $title;
    }

    /**
     * Generate html for a section summary text
     *
     * @param stdClass $section The course_section entry from DB
     * @return string HTML to output.
     */
    protected function format_summary_text($section) {
        $context = context_course::instance($section->course);
        $summarytext = file_rewrite_pluginfile_urls($section->summary, 'pluginfile.php',
            $context->id, 'course', 'section', $section->id);

        $options = new stdClass();
        $options->noclean = true;
        $options->overflowdiv = true;
        return format_text($summarytext, $section->summaryformat, $options);
    }

    /**
     * Display section and all its activities and subsections (called recursively)
     *
     * @param int|stdClass $course
     * @param int|section_info $section
     * @param int $sr section to return to (for building links)
     * @param int $level nested level on the page (in case of 0 also displays additional start/end html code)
     */
    public function display_section($course, $section, $sr, $level = 0) {
        global $PAGE;
        $course = course_get_format($course)->get_course();
        $section = course_get_format($course)->get_section($section);
        $sectionnum = $section->section;
        $movingsection = course_get_format($course)->is_moving_section();
        if ($level === 0) {
            echo html_writer::start_tag('ul', array('class' => 'flexsections'));
            if ($cancelmovingcontrol = course_get_format($course)->get_edit_control_cancelmoving()) {
                echo $this->render($cancelmovingcontrol);
            }
            if ($section->section) {
                $this->display_insert_section_here($course, $section->parent, $section->section);
            }
        }
        echo html_writer::start_tag('li',
                array('class' => "section main section-level-$level".
                    ($movingsection === $sectionnum ? ' ismoving' : '').
                    (course_get_format($course)->is_section_current($section) ? ' current' : ''),
                    'id' => 'section-'.$sectionnum));
        // display controls
        $controls = course_get_format($course)->get_section_edit_controls($section, $sr);
        if (!empty($controls)) {
            echo html_writer::start_tag('div', array('class' => 'controls'));
            foreach ($controls as $control) {
                echo $this->render($control);
            }
            echo html_writer::end_tag('div'); // .controls
        }
        echo html_writer::start_tag('div', array('class' => 'content'));
        // display section name
        if ($sectionnum && ($title = $this->section_title($sectionnum, $course, $level == 0))) {
            echo html_writer::tag('h3', $title, array('class' => 'sectionname'));
        }
        // display section description (if needed)
        if ($summary = $this->format_summary_text($section)) {
            echo html_writer::tag('div', $summary, array('class' => 'summary'));
        } else {
            echo html_writer::tag('div', '', array('class' => 'summary nosummary'));
        }
        // display section contents (activities and subsections)
        if ($section->collapsed == FORMAT_FLEXSECTIONS_EXPANDED || !$level) {
            // display resources and activities
            print_section($course, $section, null, null, true, "100%", false, $sr);
            if ($PAGE->user_is_editing()) {
                // a little hack to allow use drag&drop for moving activities if the section is empty
                if (empty(get_fast_modinfo($course)->sections[$sectionnum])) {
                    echo "<ul class=\"section img-text\">\n</ul>\n";
                }
                print_section_add_menus($course, $sectionnum, null, false, false, $sr);
            }
            // display subsections
            $children = course_get_format($course)->get_subsections($sectionnum);
            if (!empty($children) || $movingsection) {
                echo html_writer::start_tag('ul', array('class' => 'flexsections'));
                foreach ($children as $num) {
                    $this->display_insert_section_here($course, $section, $num);
                    $this->display_section($course, $num, $sr, $level+1);
                }
                $this->display_insert_section_here($course, $section);
                echo html_writer::end_tag('ul'); // .flexsections
            }
            if ($addsectioncontrol = course_get_format($course)->get_add_section_control($sectionnum)) {
                echo $this->render($addsectioncontrol);
            }
        }
        echo html_writer::end_tag('div'); // .content
        echo html_writer::end_tag('li'); // .section
        if ($level === 0) {
            if ($section->section) {
                $this->display_insert_section_here($course, $section->parent);
            }
            echo html_writer::end_tag('ul'); // .flexsections
        }
    }

    /**
     * Displays the target div for moving section (in 'moving' mode only)
     *
     * @param int|stdClass $courseorid current course
     * @param int|section_info $parent new parent section
     * @param null|int|section_info $before number of section before which we want to insert (or null if in the end)
     */
    protected function display_insert_section_here($courseorid, $parent, $before = null) {
        if ($control = course_get_format($courseorid)->get_edit_control_movehere($parent, $before)) {
            echo $this->render($control);
        }
    }

    /**
     * renders HTML for format_flexsections_edit_control
     *
     * @param format_flexsections_edit_control $control
     * @return string
     */
    protected function render_format_flexsections_edit_control(format_flexsections_edit_control $control) {
        if (!$control) {
            return '';
        }
        if ($control->class === 'movehere') {
            return html_writer::tag('li', html_writer::link($control->url, $control->text), array('class' => $control->class));
        } else if ($control->class === 'cancelmoving') {
            return html_writer::tag('li', html_writer::link($control->url, $control->text), array('class' => $control->class));
        } else if ($control->class === 'addsection') {
            return html_writer::tag('div',
                    html_writer::link($control->url, $control->text),
                    array('class' => 'mdl-right '.$control->class));
        }
        return ' ['. html_writer::link($control->url, $control->text, array('class' => $control->class)). ']';
    }
}