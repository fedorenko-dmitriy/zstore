<?php

namespace App\Pages\Register;

use Zippy\Html\DataList\DataView;
use Zippy\Html\DataList\Paginator;
use \Zippy\Html\DataList\ArrayDataSource;
use \Zippy\Binding\PropertyBinding as Prop;
use Zippy\Html\Form\CheckBox;
use Zippy\Html\Form\Date;
use Zippy\Html\Form\DropDownChoice;
use Zippy\Html\Form\Form;
use Zippy\Html\Form\TextInput;
use Zippy\Html\Form\SubmitButton;
use Zippy\Html\Panel;
use Zippy\Html\Label;
use Zippy\Html\Link\ClickLink;
use App\Entity\Doc\Document;
use App\Entity\Currency;
use App\Helper as H;
use App\Application as App;
use App\System;

/**
 * журнал  заявок
 */
class OrderCustList extends \App\Pages\Base
{

    private $_doc = null;

    /**
     *
     * @param mixed $docid Документ  должен  быть  показан  в  просмотре
     * @return DocList
     */

    public function __construct() {
        parent::__construct();
        if (false == \App\ACL::checkShowReg('OrderList'))
            return;

        $this->add(new Form('filter'))->onSubmit($this, 'filterOnSubmit');
        $this->filter->add(new Date('from', time() - (7 * 24 * 3600)));
        $this->filter->add(new Date('to', time() + (1 * 24 * 3600)));

        $this->filter->add(new TextInput('searchnumber'));
        $this->filter->add(new TextInput('searchtext'));
        $this->filter->add(new DropDownChoice('status', array(0 => 'Открытые',   3 => 'Все'), 0));


        $doclist = $this->add(new DataView('doclist', new OrderCustDataSource($this), $this, 'doclistOnRow'));
        $doclist->setSelectedClass('table-success');

        $this->add(new Paginator('pag', $doclist));
        $doclist->setPageSize(25);

        $this->add(new Panel("statuspan"))->setVisible(false);

        $this->statuspan->add(new Form('statusform'));

        $this->statuspan->statusform->add(new SubmitButton('bclose'))->onClick($this, 'statusOnSubmit');
        $this->statuspan->statusform->add(new SubmitButton('bcancel'))->onClick($this, 'statusOnSubmit');

        $this->statuspan->statusform->add(new SubmitButton('bttn'))->onClick($this, 'statusOnSubmit');
        $this->statuspan->statusform->add(new SubmitButton('bap'))->onClick($this, 'statusOnSubmit');
        $this->statuspan->statusform->add(new SubmitButton('bref'))->onClick($this, 'statusOnSubmit');
        $this->statuspan->statusform->add(new SubmitButton('binp'))->onClick($this, 'statusOnSubmit');

        $this->statuspan->add(new \App\Widgets\DocView('docview'));

        $this->doclist->Reload();
        $this->add(new ClickLink('csv', $this,'oncsv'));        
        
    }

    public function filterOnSubmit($sender) {

        $this->statuspan->setVisible(false);

        $this->doclist->Reload();
    }

    public function doclistOnRow($row) {
        $doc = $row->getDataItem();
        $doc = $doc->cast();
        
        $row->add(new Label('number', $doc->document_number));
        $row->add(new Label('date', date('d-m-Y', $doc->document_date)));
        $row->add(new Label('state', Document::getStateName($doc->state)));
        $row->add(new Label('customer', $doc->customer_name));
        $row->add(new Label('quantity', H::fqty($doc->headerdata["order_quantity"])));
        $row->add(new Label('amount', H::famt($doc->amount)));
        $row->add(new Label('currency', Currency::findArray("iso_code")[$doc->headerdata["currency_id"]]));
        $row->add(new Label('onotes', $doc->notes));

        $row->add(new ClickLink('show'))->onClick($this, 'showOnClick');
        $row->add(new ClickLink('edit'))->onClick($this, 'editOnClick');

        if ($doc->state == Document::STATE_CANCELED || $doc->state == Document::STATE_EDITED || $doc->state == Document::STATE_NEW|| $doc->state == Document::STATE_REFUSED) {
            $row->edit->setVisible(true);
        } else {
            $row->edit->setVisible(false);
        }

        //список документов   которые   могут  быть созданы  на  основании  текущего
        $basedon = $row->add(new Label('basedon'));
        $basedonlist = $doc->getRelationBased();
        if (count($basedonlist) == 0) {
            $basedon->setVisible(false);
        } else {
            $list = "";
            foreach ($basedonlist as $doctype => $docname) {
                $list .= "<a  class=\"dropdown-item\" href=\"/?p=App/Pages/Doc/" . $doctype . "&arg=/0/{$doc->document_id}\">{$docname}</a>";
            };
            $basedon = $row->add(new Label('basedlist'))->setText($list, true);
        }
    }

    public function statusOnSubmit($sender) {

        $state = $this->_doc->state;
        $payed = $this->_doc->datatag >= $this->_doc->amount; //оплачен
        $ttn = false;
        //проверяем  что есть ТТН
        $list = $this->_doc->ConnectedDocList();
        foreach ($list as $d) {
            if ($d->meta_name == 'GoodsReceipt') {
                $ttn = true;
            }
        }
        if ($sender->id == "bcancel") {
            $this->_doc->updateStatus(Document::STATE_CANCELED);
            if ($ttn)
                $this->setWarn('Для заказа уже создан приход');
        }
        if ($sender->id == "bttn") {
            App::Redirect("\\App\\Pages\\Doc\\GoodsReceipt", 0, $this->_doc->document_id);
            return;
        }


        if ($sender->id == "bap") {
            $this->_doc->updateStatus(Document::STATE_APPROVED);
        }
        if ($sender->id == "bref") {
            $this->_doc->updateStatus(Document::STATE_REFUSED);
        }
        if ($sender->id == "binp") {
            $this->_doc->updateStatus(Document::STATE_INPROCESS);
        }
        if ($sender->id == "bclose") {

            $this->_doc->datatag = $this->_doc->amount;
            $this->_doc->save();

            $this->_doc->updateStatus(Document::STATE_CLOSED);
            $this->statuspan->setVisible(false);
            if ($ttn)
                $this->setWarn('Для заявки была создана приходная накладная');
        }


        $this->doclist->Reload(false);
        $this->updateStatusButtons();
    }

    public function updateStatusButtons() {

        $this->statuspan->statusform->bclose->setVisible(true);

        $state = $this->_doc->state;

        $payed = $this->_doc->datatag >= $this->_doc->amount; //оплачен
        //доставлен
        $sent = $this->_doc->checkStates(array(Document::STATE_DELIVERED));

        $ttn = false;
        //проверяем  что есть ТТН
        $list = $this->_doc->ConnectedDocList();
        foreach ($list as $d) {
            if ($d->meta_name == 'GoodsIssue') {
                $ttn = true;
            }
        }
                $this->statuspan->statusform->binp->setVisible(false);
    
        $this->statuspan->statusform->bttn->setVisible(!$ttn);

        //отмена   если  не было оплат и доставки
        if ($this->_doc->datatag == 0  ) {
            $this->statuspan->statusform->bcancel->setVisible(false);
        } else {
            $this->statuspan->statusform->bcancel->setVisible(true);
        }

        //новый     
        if ($state == Document::STATE_CANCELED || $state == Document::STATE_EDITED || $state == Document::STATE_NEW) {
            $this->statuspan->statusform->bclose->setVisible(false);
            $this->statuspan->statusform->bcancel->setVisible(false);
            $this->statuspan->statusform->binp->setVisible(true);
        } else {

            $this->statuspan->statusform->bcancel->setVisible(true);
            $this->statuspan->statusform->bclose->setVisible(true);
        }
        $this->statuspan->statusform->bap->setVisible(false); 
        $this->statuspan->statusform->bref->setVisible(false); 
        if ($state == Document::STATE_WA) {
           $this->statuspan->statusform->bap->setVisible(true); 
           $this->statuspan->statusform->bref->setVisible(true); 
           $this->statuspan->statusform->bttn->setVisible(false); 
           $this->statuspan->statusform->binp->setVisible(false); 
        }
        if ($state == Document::STATE_APPROVED) {
            $this->statuspan->statusform->bttn->setVisible(true); 
            $this->statuspan->statusform->binp->setVisible(true); 
        }  
        if ($state == Document::STATE_INPROCESS) {
            
            $this->statuspan->statusform->binp->setVisible(false); 
        }
       if ($state == Document::STATE_REFUSED) {
            
            $this->statuspan->statusform->bttn->setVisible(false); 
        }
         //закрыт
        if ($state == Document::STATE_CLOSED) {

            $this->statuspan->statusform->bclose->setVisible(false);
            $this->statuspan->statusform->bcancel->setVisible(false);
            $this->statuspan->statusform->bttn->setVisible(false);
            $this->statuspan->statusform->bap->setVisible(false);
            $this->statuspan->statusform->setVisible(false);
        }


        $this->_tvars['askclose'] = false;
        if ($payed == false || $sent == false) {
            $this->_tvars['askclose'] = true;
        }
    }

    //просмотр
    public function showOnClick($sender) {

        $this->_doc = $sender->owner->getDataItem();
        if (false == \App\ACL::checkShowDoc($this->_doc, true))
            return;

        $this->statuspan->setVisible(true);
        $this->statuspan->docview->setDoc($this->_doc);
        $this->doclist->setSelectedRow($sender->getOwner());
        $this->doclist->Reload(true);
        $this->updateStatusButtons();
        $this->goAnkor('dankor');
        $this->_tvars['askclose'] = false;
    }

    public function editOnClick($sender) {
        $doc = $sender->getOwner()->getDataItem();
        if (false == \App\ACL::checkEditDoc($doc, true))
            return;


        App::Redirect("\\App\\Pages\\Doc\\OrderCust", $doc->document_id);
    }

    public function oncsv($sender) {
            $list = $this->doclist->getDataSource()->getItems(-1,-1,'document_id');
            $csv="";
 
            foreach($list as $d){
               $csv.=  date('Y.m.d',$d->document_date) .';';    
               $csv.=  $d->document_number .';';    
               $csv.=  $d->customer_name .';';    
               $csv.=  $d->amount  .';'; 
               $csv.=  Document::getStateName($d->state)  .';'; 
               $csv.=  $d->notes .';';     
               $csv.="\n";
            }
            $csv = mb_convert_encoding($csv, "windows-1251", "utf-8");

 
            header("Content-type: text/csv");
            header("Content-Disposition: attachment;Filename=ordercustlist.csv");
            header("Content-Transfer-Encoding: binary");

            echo $csv;
            flush();
            die;
            
    }
    
}

/**
 *  Источник  данных  для   списка  документов
 */
class OrderCustDataSource implements \Zippy\Interfaces\DataSource
{

    private $page;

    public function __construct($page) {
        $this->page = $page;
    }

    private function getWhere() {
        $user = System::getUser();

        $conn = \ZDB\DB::getConnect();

        $where = " date(document_date) >= " . $conn->DBDate($this->page->filter->from->getDate()) . " and  date(document_date) <= " . $conn->DBDate($this->page->filter->to->getDate());

        $where .= " and meta_name  = 'OrderCust' ";
  
        $status = $this->page->filter->status->getValue();
        if ($status == 0) {
            $where .= " and  state <> 9 ";
        }
       
        if ($status == 3) {
            
        }

        $st = trim($this->page->filter->searchtext->getText());
        if (strlen($st) > 2) {
            $st = $conn->qstr('%' . $st . '%');

            $where .= " and meta_name  = 'OrderCust' and  content like {$st} ";
        }
        $sn = trim($this->page->filter->searchnumber->getText());
        if (strlen($sn) > 1) { // игнорируем другие поля
            $sn = $conn->qstr('%' . $sn . '%');
            $where = " meta_name  = 'OrderCust' and document_number like  {$sn} ";
        }
        if ($user->acltype == 2) {

            $where .= " and meta_id in({$user->aclview}) ";
        }
        return $where;
    }

    public function getItemCount() {
        return Document::findCnt($this->getWhere());
    }

    public function getItems($start, $count, $sortfield = null, $asc = null) {
        $docs = Document::find($this->getWhere(), "document_date desc,document_id desc", $count, $start);

        //$l = Traversable::from($docs);
        //$l = $l->where(function ($doc) {return $doc->document_id == 169; }) ;
        //$l = $l->select(function ($doc) { return $doc; })->asArray() ;
        return $docs;
    }

    public function getItem($id) {
        
    }

}
