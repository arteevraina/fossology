<?php
/***********************************************************
 * Copyright (C) 2008-2014 Hewlett-Packard Development Company, L.P.
 *               2014 Siemens AG
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * version 2 as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 ***********************************************************/
use Fossology\Lib\BusinessRules\ClearingDecisionFilter;
use Fossology\Lib\Dao\AgentDao;
use Fossology\Lib\Dao\ClearingDao;
use Fossology\Lib\Dao\LicenseDao;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Data\AgentRef;
use Fossology\Lib\Data\LicenseRef;
use Fossology\Lib\Data\Tree\ItemTreeBounds;
use Fossology\Lib\View\LicenseRenderer;
use Fossology\Lib\Proxy\UploadTreeProxy;
use Fossology\Lib\Util\ArrayOperation;

/**
 * \file ui-browse-license.php
 * \brief browse a directory to display all licenses in this directory
 */
define("TITLE_ui_license", _("License Browser"));

class ui_browse_license extends FO_Plugin
{

  private $uploadtree_tablename = "";
  const SELECTED_ATTRIBUTE = ' selected="selected"';
  /** @var UploadDao */
  private $uploadDao;
  /** @var LicenseDao */
  private $licenseDao;
  /** @var ClearingDao */
  private $clearingDao;
  /** @var AgentDao */
  private $agentsDao;
  /** @var ClearingDecisionFilter */
  private $clearingFilter;
  /** @var array [uploadtree_id]=>cnt */
  private $filesThatShouldStillBeCleared;
  /** @var array [uploadtree_id]=>cnt */
  private $filesToBeCleared;

  function __construct()
  {
    $this->Name = "license";
    $this->Title = TITLE_ui_license;
    $this->Dependency = array("browse", "view");
    $this->DBaccess = PLUGIN_DB_READ;
    $this->LoginFlag = 0;
    parent::__construct();

    global $container;
    $this->uploadDao = $container->get('dao.upload');
    $this->licenseDao = $container->get('dao.license');
    $this->clearingDao = $container->get('dao.clearing');
    $this->agentsDao = $container->get('dao.agent');
    $this->clearingFilter = $container->get('businessrules.clearing_decision_filter');
  }

  /**
   * \brief  Only used during installation.
   * \return 0 on success, non-zero on failure.
   */
  function Install()
  {
    global $PG_CONN;
    return (int)(!$PG_CONN);
  }

  /**
   * \brief Customize submenus.
   */
  function RegisterMenus()
  {
    // For all other menus, permit coming back here.
    $URI = $this->Name . Traceback_parm_keep(array("upload", "item"));

    $Item = GetParm("item", PARM_INTEGER);
    $Upload = GetParm("upload", PARM_INTEGER);
    if (empty($Item) || empty($Upload))
      return;
    $viewLicenseURI = "view-license" . Traceback_parm_keep(array("show", "format", "page", "upload", "item"));
    $menuName = $this->Title;
    if (GetParm("mod", PARM_STRING) == $this->Name)
    {
      menu_insert("Browse::$menuName", 100);
    } else
    {
      $text = _("license histogram");
      menu_insert("Browse::$menuName", 100, $URI, $text);
      menu_insert("View::$menuName", 100, $viewLicenseURI, $text);
    }
  }

  /**
   * \brief This is called before the plugin is used.
   * It should assume that Install() was already run one time
   * (possibly years ago and not during this object's creation).
   *
   * \return true on success, false on failure.
   * A failed initialize is not used by the system.
   *
   * \note This function must NOT assume that other plugins are installed.
   */
  function Initialize()
  {
    if ($this->State != PLUGIN_STATE_INVALID)
    {
      return (1);
    } // don't re-run

    if ($this->Name !== "") // Name must be defined
    {
      global $Plugins;
      $this->State = PLUGIN_STATE_VALID;
      array_push($Plugins, $this);
    }

    return ($this->State == PLUGIN_STATE_VALID);
  }

  /**
   * \brief Given an $Uploadtree_pk, display:
   *   - The histogram for the directory BY LICENSE.
   *   - The file listing for the directory.
   */
  private function showUploadHist($Uploadtree_pk, $tag_pk)
  {
    $UniqueTagArray = array();

    $itemTreeBounds = $this->uploadDao->getItemTreeBounds($Uploadtree_pk, $this->uploadtree_tablename);

    $left = $itemTreeBounds->getLeft();
    if (empty($left))
    {
      $text = _("Job unpack/adj2nest hasn't completed.");
      $V = "<b>$text</b><p>";
      return $V;
    }

    $uploadId = GetParm('upload', PARM_NUMBER);
    $scannerAgents = array('nomos', 'monk', 'ninka');

    $V = "";
    $agentStatus = $this->createAgentStatus($scannerAgents, $uploadId);

    if (!$agentStatus)
    {
      $out = $this->handleAllScansEmpty($scannerAgents, $uploadId);
      return $out;
    }

    global $SysConf;
    $groupId = $SysConf['auth']['GroupId'];

    $selectedAgentId = GetParm('agentId', PARM_INTEGER);
    list($jsBlockLicenseHist, $VLic) = $this->createLicenseHistogram($Uploadtree_pk, $tag_pk, $itemTreeBounds, $selectedAgentId, $groupId);

    $VLic .= "\n" . $agentStatus;

    list($ChildCount, $jsBlockDirlist) = $this->createFileListing($tag_pk, $itemTreeBounds, $UniqueTagArray, $selectedAgentId, $groupId);

    /***************************************
     * Problem: $ChildCount can be zero!
     * This happens if you have a container that does not
     * unpack to a directory.  For example:
     * file.gz extracts to archive.txt that contains a license.
     * Same problem seen with .pdf and .Z files.
     * Solution: if $ChildCount == 0, then just view the license!
     *
     * $ChildCount can also be zero if the directory is empty.
     * **************************************/
    if ($ChildCount == 0)
    {
      header("Location: ?mod=view-license" . Traceback_parm_keep(array("upload", "item")));
    }

    /******  Filters  *******/
    /* Only display the filter pulldown if there are filters available
     * Currently, this is only tags.
     */
    /** @todo qualify with tag namespace to avoid tag name collisions.  * */
    /* turn $UniqueTagArray into key value pairs ($SelectData) for select list */
    $SelectData = array();
    if (count($UniqueTagArray))
    {
      foreach ($UniqueTagArray as $UTA_row)
        $SelectData[$UTA_row['tag_pk']] = $UTA_row['tag_name'];
      $V .= "Tag filter";
      $myurl = "?mod=" . $this->Name . Traceback_parm_keep(array("upload", "item"));
      $Options = " id='filterselect' onchange=\"js_url(this.value, '$myurl&tag=')\"";
      $V .= Array2SingleSelectTag($SelectData, "tag_ns_pk", $tag_pk, true, false, $Options);
    }

    $dirlistPlaceHolder = "<table border=0 id='dirlist' style=\"margin-left: 9px;\" class='semibordered'></table>\n";
    /****** Combine VF and VLic ********/
    $V .= "<table border=0 cellpadding=2 width='100%'>\n";
    $V .= "<tr><td valign='top' width='25%'>$VLic</td><td valign='top' width='75%'>$dirlistPlaceHolder</td></tr>\n";
    $V .= "</table>\n";

    $this->vars['licenseUri'] = Traceback_uri() . "?mod=popup-license&rf=";
    $this->vars['bulkUri'] = Traceback_uri() . "?mod=popup-license";


    $V .= $jsBlockDirlist;
    $V .= $jsBlockLicenseHist;
    return ($V);
  }

  /**
   * \brief This function returns the scheduler status.
   */
  function Output()
  {
    $uTime = microtime(true);
    if ($this->State != PLUGIN_STATE_READY)
    {
      return (0);
    }
    $Upload = GetParm("upload", PARM_INTEGER);
    $UploadPerm = GetUploadPerm($Upload);
    if ($UploadPerm < PERM_READ)
    {
      $text = _("Permission Denied");
      $this->vars['content'] = "<h2>$text<h2>";
      return;
    }

    $Item = GetParm("item", PARM_INTEGER);
    $tag_pk = GetParm("tag", PARM_INTEGER);
    $updateCache = GetParm("updcache", PARM_INTEGER);

    $this->vars['baseuri'] = Traceback_uri();
    $this->vars['uploadId'] = $Upload;
    $this->vars['itemId'] = $Item;

    $this->uploadtree_tablename = GetUploadtreeTableName($Upload);
    list($CacheKey, $V) = $this->cleanGetArgs($updateCache);

    $this->vars['micromenu'] = Dir2Browse($this->Name, $Item, NULL, $showBox = 0, "Browse", -1, '', '', $this->uploadtree_tablename);
    $this->vars['haveRunningResult'] = false;
    $this->vars['haveOldVersionResult'] = false;
    $this->vars['licenseArray'] = $this->licenseDao->getLicenseArray();

    $Cached = !empty($V);
    if (!$Cached && !empty($Upload))
    {
      $V .= js_url();
      $V .= $this->showUploadHist($Item, $tag_pk);
      $V .= "<button onclick='loadBulkHistoryModal();'>" . _("Show bulk history") . "</button>";
      $AddInfoText = "<br/><span id='bulkIdResult' hidden></span>";
      /** @todo move text to template */
      if ($this->vars['haveOldVersionResult'])
      {
        $AddInfoText .= "<br/>" . _("Hint: Results marked with ") . $this->vars['haveOldVersionResult'] . _(" are from an older version, or were not confirmed in latest successful scan or were found during incomplete scan.");
      }
      if ($this->vars['haveRunningResult'])
      {
        $AddInfoText .= "<br/>" . _("Hint: Results marked with ") . $this->vars['haveRunningResult'] . _(" are from the newest version and come from a currently running or incomplete scan.");
      }
      $V .= $AddInfoText;
    }


    $this->vars['content'] = $V;
    $Time = microtime(true) - $uTime;

    if ($Cached)
    {
      $text = _("This is cached view.");
      $text1 = _("Update now.");
      $this->vars['message'] = " <i>$text</i>   <a href=\"$_SERVER[REQUEST_URI]&updcache=1\"> $text1 </a>";
    } else
    {
      $text = _("Elapsed time: %.3f seconds");
      $this->vars['content'] .= sprintf("<hr/><small>$text</small>", $Time);
      if ($Time > 3.0)
        ReportCachePut($CacheKey, $V);
    }
    return;
  }

  /**
   * @param $updcache
   * @return array
   */
  public function cleanGetArgs($updcache)
  {
    /* Remove "updcache" from the GET args.
         * This way all the url's based on the input args won't be
         * polluted with updcache
         * Use Traceback_parm_keep to ensure that all parameters are in order */
    $CacheKey = "?mod=" . $this->Name . Traceback_parm_keep(array("upload", "item", "tag", "agent", "orderBy", "orderl", "orderc"));
    if ($updcache)
    {
      $_SERVER['REQUEST_URI'] = preg_replace("/&updcache=[0-9]*/", "", $_SERVER['REQUEST_URI']);
      unset($_GET['updcache']);
      $V = ReportCachePurgeByKey($CacheKey);
      return array($CacheKey, $V);
    } else
    {
      $V = ReportCacheGet($CacheKey);
      return array($CacheKey, $V);
    }
  }

  /**
   * @param $tagId
   * @param ItemTreeBounds $itemTreeBounds
   * @param $UniqueTagArray
   * @param $selectedAgentId
   * @internal param $uploadTreeId
   * @internal param $uploadId
   * @return array
   */
  private function createFileListing($tagId, ItemTreeBounds $itemTreeBounds, &$UniqueTagArray, $selectedAgentId, $groupId)
  {
    $this->vars['haveOldVersionResult'] = false;
    $this->vars['haveRunningResult'] = false;
    /** change the license result when selecting one version of nomos */
    $uploadId = $itemTreeBounds->getUploadId();
    $uploadTreeId = $itemTreeBounds->getItemId();

    $latestNomos = LatestAgentpk($uploadId, "nomos_ars");
    $newestNomos = $this->agentsDao->getCurrentAgent("nomos");
    $latestMonk = LatestAgentpk($uploadId, "monk_ars");
    $newestMonk = $this->agentsDao->getCurrentAgent("monk");
    $latestNinka = LatestAgentpk($uploadId, "ninka_ars");
    $newestNinka = $this->agentsDao->getCurrentAgent("ninka");
    $goodAgents = array('nomos' => array('name' => 'N', 'latest' => $latestNomos, 'newest' => $newestNomos, 'latestIsNewest' => $latestNomos == $newestNomos->getAgentId()),
        'monk' => array('name' => 'M', 'latest' => $latestMonk, 'newest' => $newestMonk, 'latestIsNewest' => $latestMonk == $newestMonk->getAgentId()),
        'ninka' => array('name' => 'Nk', 'latest' => $latestNinka, 'newest' => $newestNinka, 'latestIsNewest' => $latestNinka == $newestNinka->getAgentId()));

    /*******    File Listing     ************/
    $VF = ""; // return values for file listing
    $pfileLicenses = $this->licenseDao->getTopLevelLicensesPerFileId($itemTreeBounds, $selectedAgentId, array());
    /* Get ALL the items under this Uploadtree_pk */
    $Children = GetNonArtifactChildren($uploadTreeId, $this->uploadtree_tablename);

    /* Filter out Children that don't have tag */
    if (!empty($tagId))
      TagFilter($Children, $tagId, $this->uploadtree_tablename);

    if (empty($Children))
    {
      return array($ChildCount = 0, $VF);
    }

    global $Plugins;
    $ModLicView = &$Plugins[plugin_find_id("view-license")];
    $Uri = preg_replace("/&item=([0-9]*)/", "", Traceback());
    $tableData = array();

    $alreadyClearedUploadTreeView = new UploadTreeProxy($itemTreeBounds->getUploadId(),
        $options = array('skipThese' => "alreadyCleared",'groupId'=>$groupId),
        $itemTreeBounds->getUploadTreeTableName(),
        $viewName = 'already_cleared_uploadtree' . $itemTreeBounds->getUploadId());

    $alreadyClearedUploadTreeView->materialize();
    $this->filesThatShouldStillBeCleared = $alreadyClearedUploadTreeView->countMaskedNonArtifactChildren($itemTreeBounds->getItemId());
    $alreadyClearedUploadTreeView->unmaterialize();

    $noLicenseUploadTreeView = new UploadTreeProxy($itemTreeBounds->getUploadId(),
        $options = array('skipThese' => "noLicense"),
        $itemTreeBounds->getUploadTreeTableName(),
        $viewName = 'no_license_uploadtree' . $itemTreeBounds->getUploadId());
    $noLicenseUploadTreeView->materialize();
    $this->filesToBeCleared = $noLicenseUploadTreeView->countMaskedNonArtifactChildren($itemTreeBounds->getItemId());
    $noLicenseUploadTreeView->unmaterialize();

    $allDecisions = $this->clearingDao->getFileClearingsFolder($itemTreeBounds, $groupId, false);
    $editedMappedLicenses = $this->clearingFilter->filterCurrentClearingDecisions($allDecisions);
    foreach ($Children as $child)
    {
      if (empty($child))
      {
        continue;
      }
      $tableData[] = $this->createFileDataRow($child, $uploadId, $selectedAgentId, $goodAgents, $pfileLicenses, $groupId, $editedMappedLicenses, $Uri, $ModLicView, $UniqueTagArray);
    }

    $VF .= '<script>' . $this->renderTemplate('ui-browse-license_file-list.js.twig', array('aaData' => json_encode($tableData))) . '</script>';

    $ChildCount = count($tableData);
    return array($ChildCount, $VF);
  }


  /**
   * @param array $child
   * @param int $uploadId
   * @param int $selectedAgentId
   * @param AgentRef[] $goodAgents
   * @param array $pfileLicenses
   * @param int $groupId
   * @param ClearingDecision[][] $editedMappedLicenses
   * @param string $Uri
   * @param null|ClearingView $ModLicView
   * @param array $UniqueTagArray
   * @return array
   */
  private function createFileDataRow($child, $uploadId, $selectedAgentId, $goodAgents, $pfileLicenses, $groupId, $editedMappedLicenses, $Uri, $ModLicView, &$UniqueTagArray)
  {
    $fileId = $child['pfile_fk'];
    $childUploadTreeId = $child['uploadtree_pk'];

    if (!empty($fileId) && !empty($ModLicView))
    {
      $LinkUri = Traceback_uri();
      $LinkUri .= "?mod=view-license&upload=$uploadId&item=$childUploadTreeId";
      if ($selectedAgentId)
      {
        $LinkUri .= "&agentId=$selectedAgentId";
      }
    } else
    {
      $LinkUri = NULL;
    }

    /* Determine link for containers */
    $isContainer = Iscontainer($child['ufile_mode']);
    if ($isContainer)
    {
      $uploadtree_pk = DirGetNonArtifact($childUploadTreeId, $this->uploadtree_tablename);
      $LicUri = "$Uri&item=" . $uploadtree_pk;
      if ($selectedAgentId)
      {
        $LicUri .= "&agentId=$selectedAgentId";
      }
    } else
    {
      $LicUri = NULL;
    }

    /* Populate the output ($VF) - file list */
    /* id of each element is its uploadtree_pk */
    $fileName = $child['ufile_name'];
    if ($isContainer)
    {
      $fileName = "<a href='$LicUri'><span style='color: darkblue'> <b>$fileName</b> </span></a>";
    } else if (!empty($LinkUri))
    {
      $fileName = "<a href='$LinkUri'>$fileName</a>";
    }
    /* show licenses under file name */
    $childItemTreeBounds = // $this->uploadDao->getFileTreeBounds($childUploadTreeId, $this->uploadtree_tablename);
        new ItemTreeBounds($childUploadTreeId, $this->uploadtree_tablename, $child['upload_fk'], $child['lft'], $child['rgt']);
    if ($isContainer)
    {
      $licenseEntries = $this->licenseDao->getLicenseShortnamesContained($childItemTreeBounds, array());
      $editedLicenses = $this->clearingDao->getClearedLicenses($childItemTreeBounds, $groupId);
    } else
    {
      $licenseEntries = array();
      if (array_key_exists($fileId, $pfileLicenses))
      {
        foreach ($pfileLicenses[$fileId] as $shortName => $rfInfo)
        {
          $agentEntries = array();
          foreach ($rfInfo as $agent => $match)
          {
            $agentInfo = $goodAgents[$agent];
            $agentEntry = "<a href='?mod=view-license&upload=$child[upload_fk]&item=$childUploadTreeId&format=text&agentId=$match[agent_id]&licenseId=$match[license_id]#highlight'>" . $agentInfo['name'] . "</a>";

            if ($match['agent_id'] != $agentInfo['newest']->getAgentId())
            {
              $agentEntry .= "&dagger;";
              $this->vars['haveOldVersionResult'] = "&dagger;";
            } else if (!$agentInfo['latestIsNewest'])
            {
              $agentEntry .= "&sect;";
              $this->vars['haveRunningResult'] = "&sect;";
            }

            if ($match['match_percentage'] > 0)
            {
              $agentEntry .= ": $match[match_percentage]%";
            }
            $agentEntries[] = $agentEntry;
          }
          $licenseEntries[] = $shortName . " [" . implode("][", $agentEntries) . "]";
        }
      }

      /** @var ClearingDecision $decision */
      if (false !== ($decision = $this->clearingFilter->getDecisionOf($editedMappedLicenses,$childUploadTreeId, $fileId)))
      {
        $editedLicenses = $decision->getPositiveLicenses();
      }
      else
      {
        $editedLicenses = array();
      }
    }

    $editedLicenses = array_map(
      function ($licenseRef) use ($uploadId, $childUploadTreeId)
      {
        /** @var LicenseRef $licenseRef */
        return "<a href='?mod=view-license&upload=$uploadId&item=$childUploadTreeId&format=text'>" . $licenseRef->getShortName() . "</a>";
      },
      $editedLicenses
    );

    $editedLicenseList = implode(', ', $editedLicenses);
    $licenseList = implode(', ', $licenseEntries);

    $fileListLinks = FileListLinks($uploadId, $childUploadTreeId, 0, $fileId, true, $UniqueTagArray, $this->uploadtree_tablename);

    $getTextEditUser = _("Edit");
    $getTextEditBulk = _("Bulk");
    $fileListLinks .= "[<a onclick='openUserModal($childUploadTreeId)' >$getTextEditUser</a>]";
    $fileListLinks .= "[<a onclick='openBulkModal($childUploadTreeId)' >$getTextEditBulk</a>]";

    // $filesThatShouldStillBeCleared = $this->uploadDao->getContainingFileCount($childItemTreeBounds, $this->alreadyClearedUploadTreeView);
    $filesThatShouldStillBeCleared = array_key_exists($childItemTreeBounds->getItemId()
        , $this->filesThatShouldStillBeCleared) ? $this->filesThatShouldStillBeCleared[$childItemTreeBounds->getItemId()] : 0;

    // $filesToBeCleared = $this->uploadDao->getContainingFileCount($childItemTreeBounds, $this->noLicenseUploadTreeView);
    $filesToBeCleared = array_key_exists($childItemTreeBounds->getItemId()
        , $this->filesToBeCleared) ? $this->filesToBeCleared[$childItemTreeBounds->getItemId()] : 0;

    $filesCleared = $filesToBeCleared - $filesThatShouldStillBeCleared;

    $img = ($filesCleared == $filesToBeCleared) ? 'green' : 'red';

    return array($fileName, $licenseList, $editedLicenseList, $img, "$filesCleared/$filesToBeCleared", $fileListLinks);
  }


  /**
   * @param $uploadTreeId
   * @param $tagId
   * @param ItemTreeBounds $itemTreeBounds
   * @param int|null $agentId
   * @param ClearingDecision []
   * @return string
   */
  private function createLicenseHistogram($uploadTreeId, $tagId, ItemTreeBounds $itemTreeBounds, $agentId, $groupId)
  {
    $FileCount = $this->uploadDao->countPlainFiles($itemTreeBounds);
    $licenseHistogram = $this->licenseDao->getLicenseHistogram($itemTreeBounds, $orderStmt = "", $agentId);
    $counts = array();
    $licenses = $this->clearingDao->getClearedLicenses($itemTreeBounds, $groupId, $counts);

    for ($i=0;$i<count($licenses);++$i)
    {
      $editedLicensesHist[$licenses[$i]->getShortName()] = $counts[$i];
    }

    global $container;
    /** @var LicenseRenderer $licenseRenderer */
    $licenseRenderer = $container->get('view.license_renderer');
    return $licenseRenderer->renderLicenseHistogram($licenseHistogram, $editedLicensesHist, $uploadTreeId, $tagId, $FileCount);
  }

  /**
   * @param AgentRef[] $successfulAgents
   * @return string
   */
  private function buildAgentSelector($successfulAgents)
  {
    $selectedAgentId = GetParm('agentId', PARM_INTEGER);
    if (count($successfulAgents) == 1)
    {
      $agent = $successfulAgents[0];
      return "Only one revision of <b>" . $agent->getAgentName() . "</b> ran for this upload. <br/>";
    }

    $URI = Traceback_uri() . '?mod=' . Traceback_parm() . '&updcache=1';
    $output = "<form action=\"$URI\" method=\"post\"><select name=\"agentId\" id=\"agentId\">";
    $isSelected = (0 == $selectedAgentId) ? self::SELECTED_ATTRIBUTE : '';
    $output .= "<option value=\"0\" $isSelected>" . _('Latest run of all available agents') . "</option>\n";
    foreach ($successfulAgents as $agent)
    {
      $isSelected = $agent->getAgentId() == $selectedAgentId ? self::SELECTED_ATTRIBUTE : '';
      $output .= "<option value=\"" . $agent->getAgentId() . "\"$isSelected>" . $agent->getAgentName() . " " . $agent->getAgentRevision() . "</option>\n";
    }
    $output .= "</select><input type='submit' name='' value='Show'/></form>";
    return $output;
  }

  /**
   * @param $uploadId
   * @return string
   */
  public function getViewJobsLink($uploadId)
  {
    $URL = Traceback_uri() . "?mod=showjobs&upload=$uploadId";
    $linkText = _("View Jobs");
    $out = "<a href=$URL>$linkText</a>";
    return $out;
  }


  public function scheduleScan($uploadId, $agentName, $buttonText)
  {
    $out = "<span id=" . $agentName . "_span><br><button type=\"button\" id=$agentName name=$agentName onclick='scheduleScan($uploadId, \"agent_$agentName\",\"#job" . $agentName . "IdResult\")'>$buttonText</button> </span>";
    $out .= "<br><span id=\"job" . $agentName . "IdResult\" name=\"job" . $agentName . "IdResult\" hidden></span>";

    return $out;
  }


  /**
   * @param $scannerAgents
   * @param $uploadId
   * @return string
   */
  private function handleAllScansEmpty($scannerAgents, $uploadId)
  {
    $this->vars['noUploadHist'] = TRUE;
    $out = _("There is no successful scan for this upload, please schedule one license scanner on this upload. ");

    foreach ($scannerAgents as $agentName)
    {
      $runningJobs = $this->agentsDao->getRunningAgentIds($uploadId, $agentName);
      if (count($runningJobs) > 0)
      {
        $out .= _("The agent ") . $agentName . _(" was already scheduled. Maybe it is running at the moment?");
        $out .= $this->getViewJobsLink($uploadId);
        $out .= "<br/>";
      }
    }
    $out .= _("Do you want to ");
    $link = Traceback_uri() . '?mod=agent_add&upload=' . $uploadId;
    $out .= "<a href='$link'>" . _("schedule agents") . "</a>";
    return $out;
  }

  /**
   * @param $scannerAgents
   * @param $uploadId
   * @return array
   */
  private function createAgentStatus($scannerAgents, $uploadId)
  {
    $output = "";

    $allSuccessfulAgents = array();

    foreach ($scannerAgents as $agentName)
    {
      $agentHasArsTable = $this->agentsDao->arsTableExists($agentName);
      if (empty($agentHasArsTable))
      {
        continue;
      }

      $currentAgent = $this->agentsDao->getCurrentAgent($agentName);
      $successfulAgents = $this->agentsDao->getSuccessfulAgentRuns($agentName, $uploadId);
      $allSuccessfulAgents = array_merge($allSuccessfulAgents, $successfulAgents);
      $latestSuccessfulAgent = count($successfulAgents) > 0 ? $successfulAgents[0] : false;

      $output .= "<p>\n";
      if (false === $latestSuccessfulAgent)
      {
        $output .= _("The agent") . " <b>$agentName</b> " . _("has not been run on this upload.");

        $runningJobs = $this->agentsDao->getRunningAgentIds($uploadId, $agentName);
        if (count($runningJobs) > 0)
        {
          $output .= _("But there were scheduled jobs for this agent. So it is either running or has failed.");
          $output .= $this->getViewJobsLink($uploadId);
          $output .= $this->scheduleScan($uploadId, $agentName, sprintf(_("Reschedule %s scan"), $agentName));
        } else
        {
          $output .= $this->scheduleScan($uploadId, $agentName, sprintf(_("Schedule %s scan"), $agentName));
        }
        $output .= "</p>\n";
        continue;
      }

      $output .= _("The latest results of agent") . " <b>$agentName</b> " . _("are from revision ") . $latestSuccessfulAgent->getAgentRevision() . ".";

      if ($latestSuccessfulAgent->getAgentId() != $currentAgent->getAgentId())
      {
        $runningJobs = $this->agentsDao->getRunningAgentIds($uploadId, $agentName);
        if (in_array($currentAgent->getAgentId(), $runningJobs))
        {
          $output .= _(" The newest agent revision ") . $currentAgent->getAgentRevision() . _(" is scheduled to run on this upload.");
          $output .= $this->getViewJobsLink($uploadId);
          $output .= " " . _("or") . " ";
        } else
        {
          $output .= _(" The newest agent revision ") . $currentAgent->getAgentRevision() . _(" has not been run on this upload.");

        }
        $output .= $this->scheduleScan($uploadId, $agentName, sprintf(_("Schedule %s scan"), $agentName));
      }
      $output .= "</p>\n";
    }

    $header = "<h3>" . _("Scanner details") . "</h3>";
    $header .= $this->buildAgentSelector($allSuccessfulAgents) . "\n";

    return empty($allSuccessfulAgents) ? false : ($header . $output);
  }

  public function getTemplateName()
  {
    return "browse_license.html.twig";
  }

}

$NewPlugin = new ui_browse_license;
$NewPlugin->Initialize();
