<?php

/**
 * @group pholio
 */
final class PholioMockEditController extends PholioController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = idx($data, 'id');
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    if ($this->id) {
      $mock = id(new PholioMockQuery())
        ->setViewer($user)
        ->needImages(true)
        ->requireCapabilities(
          array(
            PhabricatorPolicyCapability::CAN_VIEW,
            PhabricatorPolicyCapability::CAN_EDIT,
          ))
        ->withIDs(array($this->id))
        ->executeOne();

      if (!$mock) {
        return new Aphront404Response();
      }

      $title = pht('Edit Mock');

      $is_new = false;
      $mock_images = $mock->getImages();
      $files = mpull($mock_images, 'getFile');
      $mock_images = mpull($mock_images, null, 'getFilePHID');
    } else {
      $mock = PholioMock::initializeNewMock($user);

      $title = pht('Create Mock');

      $is_new = true;
      $files = array();
      $mock_images = array();
    }

    if ($is_new) {
      $v_projects = array();
    } else {
      $v_projects = PhabricatorEdgeQuery::loadDestinationPHIDs(
        $mock->getPHID(),
        PhabricatorEdgeConfig::TYPE_OBJECT_HAS_PROJECT);
      $v_projects = array_reverse($v_projects);
    }

    $e_name = true;
    $e_images = count($mock_images) ? null : true;
    $errors = array();
    $posted_mock_images = array();

    $v_name = $mock->getName();
    $v_desc = $mock->getDescription();
    $v_status = $mock->getStatus();
    $v_view = $mock->getViewPolicy();
    $v_edit = $mock->getEditPolicy();
    $v_cc = PhabricatorSubscribersQuery::loadSubscribersForPHID(
      $mock->getPHID());

    if ($request->isFormPost()) {
      $xactions = array();

      $type_name = PholioTransactionType::TYPE_NAME;
      $type_desc = PholioTransactionType::TYPE_DESCRIPTION;
      $type_status = PholioTransactionType::TYPE_STATUS;
      $type_view = PhabricatorTransactions::TYPE_VIEW_POLICY;
      $type_edit = PhabricatorTransactions::TYPE_EDIT_POLICY;
      $type_cc   = PhabricatorTransactions::TYPE_SUBSCRIBERS;

      $v_name = $request->getStr('name');
      $v_desc = $request->getStr('description');
      $v_status = $request->getStr('status');
      $v_view = $request->getStr('can_view');
      $v_edit = $request->getStr('can_edit');
      $v_cc   = $request->getArr('cc');
      $v_projects = $request->getArr('projects');

      $mock_xactions = array();
      $mock_xactions[$type_name] = $v_name;
      $mock_xactions[$type_desc] = $v_desc;
      $mock_xactions[$type_status] = $v_status;
      $mock_xactions[$type_view] = $v_view;
      $mock_xactions[$type_edit] = $v_edit;
      $mock_xactions[$type_cc]   = array('=' => $v_cc);

      if (!strlen($request->getStr('name'))) {
        $e_name = 'Required';
        $errors[] = pht('You must give the mock a name.');
      }

      $file_phids = $request->getArr('file_phids');
      if ($file_phids) {
        $files = id(new PhabricatorFileQuery())
          ->setViewer($user)
          ->withPHIDs($file_phids)
          ->execute();
        $files = mpull($files, null, 'getPHID');
        $files = array_select_keys($files, $file_phids);
      } else {
        $files = array();
      }

      if (!$files) {
        $e_images = pht('Required');
        $errors[] = pht('You must add at least one image to the mock.');
      } else {
        $mock->setCoverPHID(head($files)->getPHID());
      }

      foreach ($mock_xactions as $type => $value) {
        $xactions[$type] = id(new PholioTransaction())
          ->setTransactionType($type)
          ->setNewValue($value);
      }

      $order = $request->getStrList('imageOrder');
      $sequence_map = array_flip($order);
      $replaces = $request->getArr('replaces');
      $replaces_map = array_flip($replaces);

      /**
       * Foreach file posted, check to see whether we are replacing an image,
       * adding an image, or simply updating image metadata. Create
       * transactions for these cases as appropos.
       */
      foreach ($files as $file_phid => $file) {
        $replaces_image_phid = null;
        if (isset($replaces_map[$file_phid])) {
          $old_file_phid = $replaces_map[$file_phid];
          if ($old_file_phid != $file_phid) {
            $old_image = idx($mock_images, $old_file_phid);
            if ($old_image) {
              $replaces_image_phid = $old_image->getPHID();
            }
          }
        }

        $existing_image = idx($mock_images, $file_phid);

        $title = (string)$request->getStr('title_'.$file_phid);
        $description = (string)$request->getStr('description_'.$file_phid);
        $sequence = $sequence_map[$file_phid];

        if ($replaces_image_phid) {
          $replace_image = id(new PholioImage())
            ->setReplacesImagePHID($replaces_image_phid)
            ->setFilePhid($file_phid)
            ->attachFile($file)
            ->setName(strlen($title) ? $title : $file->getName())
            ->setDescription($description)
            ->setSequence($sequence);
          $xactions[] = id(new PholioTransaction())
            ->setTransactionType(
              PholioTransactionType::TYPE_IMAGE_REPLACE)
            ->setNewValue($replace_image);
          $posted_mock_images[] = $replace_image;
        } else if (!$existing_image) { // this is an add
          $add_image = id(new PholioImage())
            ->setFilePhid($file_phid)
            ->attachFile($file)
            ->setName(strlen($title) ? $title : $file->getName())
            ->setDescription($description)
            ->setSequence($sequence);
          $xactions[] = id(new PholioTransaction())
            ->setTransactionType(PholioTransactionType::TYPE_IMAGE_FILE)
            ->setNewValue(
              array('+' => array($add_image)));
          $posted_mock_images[] = $add_image;
        } else {
          $xactions[] = id(new PholioTransaction())
            ->setTransactionType(PholioTransactionType::TYPE_IMAGE_NAME)
            ->setNewValue(
              array($existing_image->getPHID() => $title));
          $xactions[] = id(new PholioTransaction())
            ->setTransactionType(
              PholioTransactionType::TYPE_IMAGE_DESCRIPTION)
              ->setNewValue(
                array($existing_image->getPHID() => $description));
          $xactions[] = id(new PholioTransaction())
            ->setTransactionType(
              PholioTransactionType::TYPE_IMAGE_SEQUENCE)
              ->setNewValue(
                array($existing_image->getPHID() => $sequence));

          $posted_mock_images[] = $existing_image;
        }
      }
      foreach ($mock_images as $file_phid => $mock_image) {
        if (!isset($files[$file_phid]) && !isset($replaces[$file_phid])) {
          // this is an outright delete
          $xactions[] = id(new PholioTransaction())
            ->setTransactionType(PholioTransactionType::TYPE_IMAGE_FILE)
            ->setNewValue(
              array('-' => array($mock_image)));
        }
      }

      if (!$errors) {
        $proj_edge_type = PhabricatorEdgeConfig::TYPE_OBJECT_HAS_PROJECT;
        $xactions[] = id(new PholioTransaction())
          ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
          ->setMetadataValue('edge:type', $proj_edge_type)
          ->setNewValue(array('=' => array_fuse($v_projects)));

        $mock->openTransaction();
        $editor = id(new PholioMockEditor())
          ->setContentSourceFromRequest($request)
          ->setContinueOnNoEffect(true)
          ->setActor($user);

        $xactions = $editor->applyTransactions($mock, $xactions);

        $mock->saveTransaction();

        return id(new AphrontRedirectResponse())
          ->setURI('/M'.$mock->getID());
      }
    }

    if ($this->id) {
      $submit = id(new AphrontFormSubmitControl())
        ->addCancelButton('/M'.$this->id)
        ->setValue(pht('Save'));
    } else {
      $submit = id(new AphrontFormSubmitControl())
        ->addCancelButton($this->getApplicationURI())
        ->setValue(pht('Create'));
    }

    $policies = id(new PhabricatorPolicyQuery())
      ->setViewer($user)
      ->setObject($mock)
      ->execute();

    // NOTE: Make this show up correctly on the rendered form.
    $mock->setViewPolicy($v_view);
    $mock->setEditPolicy($v_edit);

    $handles = id(new PhabricatorHandleQuery())
      ->setViewer($user)
      ->withPHIDs($v_cc)
      ->execute();

    $image_elements = array();
    if ($posted_mock_images) {
      $display_mock_images = $posted_mock_images;
    } else {
      $display_mock_images = $mock_images;
    }
    foreach ($display_mock_images as $mock_image) {
      $image_elements[] = id(new PholioUploadedImageView())
        ->setUser($user)
        ->setImage($mock_image)
        ->setReplacesPHID($mock_image->getFilePHID());
    }

    $list_id = celerity_generate_unique_node_id();
    $drop_id = celerity_generate_unique_node_id();
    $order_id = celerity_generate_unique_node_id();

    $list_control = phutil_tag(
      'div',
      array(
        'id' => $list_id,
        'class' => 'pholio-edit-list',
      ),
      $image_elements);

    $drop_control = phutil_tag(
      'div',
      array(
        'id' => $drop_id,
        'class' => 'pholio-edit-drop',
      ),
      'Drag and drop images here to add them to the mock.');

    $order_control = phutil_tag(
      'input',
      array(
        'type' => 'hidden',
        'name' => 'imageOrder',
        'id' => $order_id,
      ));

    Javelin::initBehavior(
      'pholio-mock-edit',
      array(
        'listID' => $list_id,
        'dropID' => $drop_id,
        'orderID' => $order_id,
        'uploadURI' => '/file/dropupload/',
        'renderURI' => $this->getApplicationURI('image/upload/'),
        'pht' => array(
          'uploading' => pht('Uploading Image...'),
          'uploaded' => pht('Upload Complete...'),
          'undo' => pht('Undo'),
          'removed' => pht('This image will be removed from the mock.'),
        ),
      ));

    if ($v_projects) {
      $project_handles = $this->loadViewerHandles($v_projects);
    } else {
      $project_handles = array();
    }

    require_celerity_resource('pholio-edit-css');
    $form = id(new AphrontFormView())
      ->setUser($user)
      ->appendChild($order_control)
      ->appendChild(
        id(new AphrontFormTextControl())
        ->setName('name')
        ->setValue($v_name)
        ->setLabel(pht('Name'))
        ->setError($e_name))
      ->appendChild(
        id(new PhabricatorRemarkupControl())
        ->setName('description')
        ->setValue($v_desc)
        ->setLabel(pht('Description'))
        ->setUser($user));

    if ($this->id) {
      $form->appendChild(
        id(new AphrontFormSelectControl())
        ->setLabel(pht('Status'))
        ->setName('status')
        ->setValue($mock->getStatus())
        ->setOptions($mock->getStatuses()));
    } else {
      $form->addHiddenInput('status', 'open');
    }

    $form->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setLabel(pht('Projects'))
          ->setName('projects')
          ->setValue($project_handles)
          ->setDatasource('/typeahead/common/projects/'))
      ->appendChild(
        id(new AphrontFormTokenizerControl())
        ->setLabel(pht('CC'))
        ->setName('cc')
        ->setValue($handles)
        ->setUser($user)
        ->setDatasource('/typeahead/common/mailable/'))
      ->appendChild(
        id(new AphrontFormPolicyControl())
        ->setUser($user)
        ->setCapability(PhabricatorPolicyCapability::CAN_VIEW)
        ->setPolicyObject($mock)
        ->setPolicies($policies)
        ->setName('can_view'))
      ->appendChild(
        id(new AphrontFormPolicyControl())
        ->setUser($user)
        ->setCapability(PhabricatorPolicyCapability::CAN_EDIT)
        ->setPolicyObject($mock)
        ->setPolicies($policies)
        ->setName('can_edit'))
      ->appendChild(
        id(new AphrontFormMarkupControl())
        ->setValue($list_control))
      ->appendChild(
        id(new AphrontFormMarkupControl())
        ->setValue($drop_control)
        ->setError($e_images))
      ->appendChild($submit);

    $form_box = id(new PHUIObjectBoxView())
      ->setHeaderText($title)
      ->setFormErrors($errors)
      ->setForm($form);

    $crumbs = $this->buildApplicationCrumbs();
    if (!$is_new) {
      $crumbs->addTextCrumb($mock->getMonogram(), '/'.$mock->getMonogram());
    }
    $crumbs->addTextCrumb($title);

    $content = array(
      $crumbs,
      $form_box,
    );

    return $this->buildApplicationPage(
      $content,
      array(
        'title' => $title,
        'device' => true,
      ));
  }

}
