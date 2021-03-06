<?php

namespace Controllers\Admin;

class ExportController extends \Controllers\Admin\AdminBaseController {

	public function get() {

		// Get the next event
		$event = $this->app->db->queryRow('SELECT * FROM events WHERE end_time > NOW() ORDER BY start_time ASC LIMIT 1');

		$data = array();

		if ($this->routeargs['export'] == 'panels') {
			$sessions = $this->app->db->queryAllRows('SELECT * FROM sessions WHERE event_id=%d AND type=%s', $event['id'], 'session');
			foreach ($sessions as $session) {
				$data[self::slugify($session['name'])] = array('panelists'=>array(), 'questions'=>array());
				$panelists = $this->app->db->queryAllRows('SELECT pe.twitter_username, pe.family_name, pe.given_name, pe.org, pa.role FROM participation pa INNER JOIN people pe ON pa.person_id=pe.id WHERE pa.session_id=%d AND role != %s AND panel_status=%s', $session['id'], 'Delegate', 'Confirmed');
				foreach ($panelists as $panelist) {
					$data[self::slugify($session['name'])]['panelists'][] = array(
						'Surname' => $panelist['family_name'],
						'FirstName' => $panelist['given_name'],
						'mod' => ($panelist['role'] == 'Moderator'),
						'pic' => 'http://edgeconf.com/images/heads/'.self::slugify($panelist['given_name'].'-'.$panelist['family_name'], '-').'.jpg',
						'twitter' => $panelist['twitter_username'],
						'org' => $panelist['org']
					);
				}
			}
			$gsheet = new \GSheet($this->app->config->google->question_sheet, $this->app->config->google->question_sheet_gid);
			foreach ($gsheet as $row) {
				if (is_numeric($row[1]) and !empty($row[2])) {
					$data[self::slugify($row[0])]['questions'][] = $row[2];
				}
			}
		} elseif ($this->routeargs['export'] == 'attendees') {
			$attendees = $this->app->db->queryAllRows('SELECT pe.id, pe.family_name, pe.given_name, pe.email, pe.org, a.ticket_type, a.ticket_date FROM people pe INNER JOIN attendance a ON a.person_id=pe.id WHERE a.event_id=%d AND ticket_type IS NOT NULL', $event['id']);
			foreach ($attendees as $pe) {
				$data[] = array(
					'Attendee no.' => $pe['id'],
					'Date' => $pe['ticket_date']->format('j M Y'),
					'Surname' => $pe['family_name'],
					'FirstName' => $pe['given_name'],
					'Email' => $pe['email'],
					'Ticket Type' => $pe['ticket_type'],
					'org' => $pe['org'],
					'Sessions of interest' => $this->app->db->queryList('SELECT s.name FROM sessions s INNER JOIN participation p ON s.id=p.session_id WHERE p.person_id=%d AND s.event_id=%d', $pe['id'], $event['id'])
				);
			}
		}
		if (empty($this->routeargs['format']) or $this->routeargs['format'] === 'json') {
			$this->resp->setJSON($data);
		} else {
			$csv = array(join(",", array_keys($data[0])));
			foreach ($data as $row) {
				$csvline = array();
				foreach ($row as $field) $csvline[] = is_string($field) ? json_encode($field) : json_encode(json_encode($field));
				$csv[] = join(',', $csvline);
			}
			$this->resp->setContent(join("\n", $csv));
			$this->resp->setHeader('Content-Type', 'text/csv');
			$this->resp->setHeader('Content-disposition', 'attachment; filename=export.csv');
		}
	}

	private static function slugify($str, $sep='_') {
		return str_replace(' ', $sep, strtolower($str));
	}
}
