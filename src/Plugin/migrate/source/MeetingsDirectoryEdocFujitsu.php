<?php

namespace Drupal\os2web_meetings_edoc_fujitsu\Plugin\migrate\source;

use Drupal\Component\FileSystem\RegexDirectoryIterator;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\node\Entity\Node;
use Drupal\os2web_meetings\Entity\Meeting;
use Drupal\os2web_meetings\Form\SettingsForm;
use Drupal\os2web_meetings\Plugin\migrate\source\MeetingsDirectory;
use Drupal\migrate\Row;

/**
 * Source plugin for retrieving data via URLs.
 *
 * @MigrateSource(
 *   id = "os2web_meetings_directory_edoc_fujitsu"
 * )
 */
class MeetingsDirectoryEdocFujitsu extends MeetingsDirectory {

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration) {
    $urls = $this->collectAgendaUrls($configuration);
    $configuration['urls'] = $urls;

    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
  }

  /**
   * {@inheritdoc}
   */
  public function collectAgendaUrls($configuration) {
    $manifestPaths = [];
    $agendaPaths = [];
    $agendas = [];

    // Traverse through the directory (not recursively)
    $path = $this->getMeetingsManifestPath();
    $iterator = new RegexDirectoryIterator($path, $configuration['pattern']);
    foreach ($iterator as $fileinfo) {
      $manifestPaths[] = $fileinfo->getPathname();
    }

    // Loading agenda XML's.
    foreach ($manifestPaths as $manifest) {
      $manifalestRealPath = \Drupal::service('file_system')->realpath($manifest);

      libxml_clear_errors();
      $manifestXml = simplexml_load_file($manifalestRealPath);
      foreach (libxml_get_errors() as $error) {
        $error_string = self::parseLibXmlError($error);
        \Drupal::logger('os2web_meetings')->error('URL skipped. XML invalid syntax: ' . $error_string);
        return FALSE;
      }

      // Returned as array with one element.
      $isPublish = $manifestXml->xpath('/Root/edoc:Notification/edoc:Publish');
      $isPublish = (string)array_shift($isPublish);
      $agendaId =  $manifestXml->xpath('/Root/edoc:Notification/edoc:AgendaIdentifier');
      $agendaId = (string)array_shift($agendaId);
      $agendaTimestamp = $manifestXml->xpath('/Root/edoc:Notification/edoc:Timestamp');
      $agendaTimestamp = strtotime((string)array_shift($agendaTimestamp));

      if (filter_var($isPublish, FILTER_VALIDATE_BOOLEAN)) {
        // Returned as array with one element.
        $agendaPath = $manifestXml->xpath('/Root/edoc:Notification/edoc:PathToXml');
        $agendaPath = (string) array_shift($agendaPath);
        $agendaPath = $this->invertPathsBackslashes($agendaPath);
        if (isset($agendas[$agendaId])) {
          if ($agendaTimestamp < $agendas[$agendaId]['lastModified']) {
            continue;
          }
        }
        $agendas[$agendaId] = [
          'uri' =>  $this->getMeetingsManifestPath() . $agendaPath,
          'lastModified' => $agendaTimestamp
          ];
      }
    }
    foreach($agendas as $agenda) {
      $agendaPaths[] = $agenda['uri'];
    }

    return $agendaPaths;
  }

  /**
   * {@inheritDoc}
   */
  public function prepareRow(Row $row) {
    $source = $row->getSource();

    // Altering the title.
    $start_date = $source['meeting_start_date'];
    $start_date_ts = $this->convertDateToTimestamp($start_date);

    /** @var DateFormatterInterface $dateFormatter */
    $dateFormatter = \Drupal::service('date.formatter');
    $formatted_date = $dateFormatter->format($start_date_ts, 'os2core_date_medium', '', NULL, 'da');

    // New title will be [Meeting title den [date in os2core_date_medium format]
    $title = $source['title'] . ' den ' . $formatted_date;

    $row->setSourceProperty('title', $title);

    return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   */
  public function getMeetingsManifestPath() {
    return \Drupal::config(SettingsForm::$configName)
      ->get('edoc_fujitsu_meetings_manifest_path');
  }

  /**
   * {@inheritdoc}
   */
  public function convertAgendaIdToCanonical(array $source) {
    return $source['agenda_id'];
  }

  /**
   * {@inheritdoc}
   */
  public function convertAgendaAccessToCanonical(array $source) {
    return filter_var($source['agenda_access'], FILTER_VALIDATE_BOOLEAN);
  }

  /**
   * {@inheritdoc}
   */
  public function convertAgendaTypeToCanonical(array $source) {
    if (strcasecmp($source['agenda_type'], 'Agenda') === 0) {
      return MeetingsDirectory::AGENDA_TYPE_DAGSORDEN;
    }
    else {
      return MeetingsDirectory::AGENDA_TYPE_REFERAT;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function convertStartDateToCanonical(array $source) {
    $start_date = $source['meeting_start_date'];

    return $this->convertDateToTimestamp($start_date);
  }

  /**
   * {@inheritdoc}
   */
  public function convertEndDateToCanonical(array $source) {
    $end_date = $source['meeting_end_date'];

    return $this->convertDateToTimestamp($end_date);
  }

  /**
   * {@inheritdoc}
   */
  public function convertAgendaDocumentToCanonical(array $source) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function convertCommitteeToCanonical(array $source) {
    $id = $source['committee_id'];
    $name = $source['committee_name'];
    return [
      'id' => $id,
      'name' => $name,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function convertLocationToCanonical(array $source) {
    $id = $source['location_name'];
    $name = $source['location_name'];
    return [
      'id' => $id,
      'name' => $name,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function convertBulletPointsToCanonical(array $source) {
    $canonical_bullet_points = [];
    $source_bullet_points = $source['bullet_points'];

    foreach ($source_bullet_points as $bullet_point) {
      // Getting title.
      $title = $bullet_point['HandlingItem']['Title'];

      // Skipping bullet points with no titles.
      if (!$title) {
        continue;
      }

      // Getting fields.
      $id = $bullet_point['Identifier'];
      $bpNumber = $bullet_point['HandlingItem']['SerialNo'];
      $publishingType = $bullet_point['AccessIsPublic'];
      $access = filter_var($publishingType, FILTER_VALIDATE_BOOLEAN);
      $caseno = $bullet_point['HandlingItem']['CaseNumber'];
      $comname = $bullet_point['RuleOfSpeaking'];

      // Getting attachments (text).
      $source_attachments = $bullet_point['HandlingItem']['CasePresentations'];
      $canonical_attachments = [];
      if (is_array($source_attachments) && array_key_exists('CasePresentation', $source_attachments)) {
        $canonical_attachments = $this->convertAttachmentsToCanonical($source_attachments['CasePresentation']);
      }

      // Getting enclosures (files).
      $source_enclosures = $bullet_point['HandlingItem']['Attachments'];
      $canonical_enclosures = [];
      if (is_array($source_enclosures) && array_key_exists('Attachment', $source_enclosures)) {
        /*
         * If $source_enclosures['Attachment'] is assosiative array,
         * we need to add it to array for import work correctly.
         */
        if (count(array_filter(array_keys($source_enclosures['Attachment']), 'is_string')) == count($source_enclosures['Attachment'])) {
          $source_enclosures['Attachment'] = [$source_enclosures['Attachment']];
        }
        $canonical_enclosures = $this->convertEnclosuresToCanonical($source_enclosures);
      }

      $canonical_bullet_points[] = [
        'id' => $id,
        'number' => $bpNumber,
        'title' => $title,
        'access' => $access,
        'case_nr' => $caseno,
        'com_name' => $comname,
        'attachments' => $canonical_attachments,
        'enclosures' =>  $canonical_enclosures,
      ];
    }

    usort($canonical_bullet_points, function ($item1, $item2) {
    if ($item1['number'] == $item2['number']) return 0;
    return $item1['number'] < $item2['number'] ? -1 : 1;
    });

    return $canonical_bullet_points;
  }

  /**
   * {@inheritdoc}
   */
  public function convertAttachmentsToCanonical(array $source_attachments, $access = TRUE) {
    $canonical_attachments = [];

    foreach ($source_attachments as $source_attachment) {
      if (!empty($source_attachment['Content'])) {
        // Using title as ID, as we don't have a real one.
        $id = $source_attachment['Title'];

        // Improve body, replace all backslashes with forward slashes in src.
        $body = $source_attachment['Content'];
        preg_match_all('/src="([^"]+)"/', $body, $matches);
        foreach ($matches[1] as $originalSrc) {
          $newSrc = str_replace('\\', '/', $originalSrc);
          $body = str_replace($originalSrc, $newSrc, $body);
        }

        $canonical_attachments[] = [
          'id' => $id,
          'title' => $source_attachment['Title'],
          'body' => $body,
          'access' => TRUE,
        ];
      }
    }

    return $canonical_attachments;
  }

  /**
   * {@inheritdoc}
   */
  public function convertEnclosuresToCanonical(array $source_enclosures) {
    $canonical_enclosures = [];

    foreach ($source_enclosures['Attachment'] as $enclosure) {
      $id = $enclosure['Identifier'];
      $title = $enclosure['Title'];
      $access = TRUE;
      $uri = $enclosure['Link'];

      $uri = $this->invertPathsBackslashes($uri);
      $uri = $this->capitalizeExtension($uri);

      $canonical_enclosures[] = [
        'id' => $id,
        'title' => $title,
        'uri' => $uri,
        'access' => $access,
      ];
    }

    return $canonical_enclosures;
  }

  /**
   * Converts Danish specific string date into timestamp in UTC.
   *
   * @param string $dateStr
   *   Date as string.
   *
   * @return int
   *   Timestamp in UTC.
   *
   * @throws \Exception
   */
  private function convertDateToTimestamp($dateStr) {
    $dateTime = new \DateTime($dateStr, new \DateTimeZone('Europe/Copenhagen'));

    return $dateTime->getTimestamp();
  }

  /**
   * {@inheritdoc}
   */
  public function convertParticipantToCanonical(array $source) {
    $participants =  $source['participants'];

    $canonical_participants = ['participants' => [], 'participants_canceled' => []];

    foreach ($participants as $participant) {
      if (filter_var($participant['Participate'], FILTER_VALIDATE_BOOLEAN)) {
        $canonical_participants['participants'][] = (string) $participant['Name'];
      }
      else {
        $canonical_participants['participants_canceled'][] = (string) $participant['Name'];
      }
    }

    return $canonical_participants;
  }

  /**
   * Replacing backslash with normal slash.
   */

  /**
   * Replacing backslash with normal slash.
   *
   * @param $path
   *   Path string
   *
   * @return string|string[]
   *   String with the backslashes being inverted.
   */
  private function invertPathsBackslashes($path) {
    return str_replace('\\', '/', $path);
  }

  /**
   * Capitalising the extension.
   *
   * @param $path
   *   Path string
   *
   * @return string|string[]
   *   URI with file extension being capitalized.
   */
  private function capitalizeExtension($path) {
    $path_parts = pathinfo($path);

    $extension = $path_parts['extension'];
    $capExtension = strtoupper($extension);

    // Avoiding wrong replacements by adding dot.
    return str_replace(".$extension", ".$capExtension", $path);
  }

  /**
   * {@inheritdoc}
   */
  public function postImport(MigrateImportEvent $event) {
    parent::postImport($event);

    // Find all meetings.
    $query = \Drupal::entityQuery('node')->accessCheck(false);
    $query->condition('type', 'os2web_meetings_meeting');
    $query->condition('field_os2web_m_source', $this->getPluginId());
    $entity_ids = $query->execute();

    $meetings = Node::loadMultiple($entity_ids);

    // Group meetings as:
    // $groupedMeetings[<meeting_id>][<agenda_id>] = <node_id> .
    $groupedMeetings = [];
    foreach ($meetings as $meeting) {
      $os2webMeeting = new Meeting($meeting);

      $meeting_id = $os2webMeeting->getMeetingId();
      $agenda_id = $os2webMeeting->getEsdhId();

      $groupedMeetings[$meeting_id][$agenda_id] = $os2webMeeting->id();

      // Sorting agendas, so that lowest agenda ID is always the first.
      sort($groupedMeetings[$meeting_id]);
    }

    // Process grouped meetings and set addendum fields.
    foreach ($groupedMeetings as $meeting_id => $agendas) {
      // Skipping if agenda count is 1.
      if (count($agendas) == 1) {
        continue;
      }

      $mainAgendaNodedId = array_shift($agendas);

      foreach ($agendas as $agenda_id => $node_id) {
        // Getting the meeting.
        $os2webMeeting = new Meeting($meetings[$node_id]);

        // Setting addendum field, meeting is saved inside a function.
        $os2webMeeting->setAddendum($mainAgendaNodedId);
      }
    }
  }
}
