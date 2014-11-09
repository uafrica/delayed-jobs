<div class="row">
    <div class="col-xs-12 col-sm-7 col-md-7 col-lg-4">
        <h1 class="page-title txt-color-blueDark">
            <i class="fa fa-tags fa-fw "></i> 
            Delayed Jobs 
        </h1>
    </div>

    <div class="clearfix pg-btn-right pull-right"><a href="/accounts/add" target="_self" class="btn btn-labeled btn-primary"> <span class="btn-label"><i class="fa fa-plus"></i></span>Add Account </a></div>

    <div id="header_paginate" class="pull-right">
        <ul class="pagination">
            <?php
            echo $this->Paginator->prev('<i class="fa fa-chevron-left"></i>', array('escape' => false, 'tag' => 'li', 'disabledTag' => 'a'), null, array('escape' => false, 'class' => 'disabled', 'tag' => 'li', 'disabledTag' => 'a'));
            //echo $this->Paginator->numbers(array('separator' => '', 'class' => 'paging', 'tag' => 'li', 'currentClass' => 'active', 'currentTag' => 'a'));
            echo $this->Paginator->next('<i class="fa fa-chevron-right"></i>', array('escape' => false, 'tag' => 'li', 'disabledTag' => 'a'), null, array('escape' => false, 'class' => 'disabled', 'tag' => 'li', 'disabledTag' => 'a'));
            ?>
        </ul>
    </div>
</div>

<?php if(count($accounts) <= 0){//no products?>
<div id="product_index">
    <div class="well well_empty">
        <h2>You do not have any accounts yet.</h2>
        <h4>Start by creating your first account.</h4>
        <div class="clearfix"><a href="/accounts/add" target="_self" class="btn btn-labeled btn-success"> <span class="btn-label"><i class="fa fa-check"></i></span>Get Started</a></div>
    </div>
</div>
<?php }else{?>

<div id="product_index">
   <div class="well">
    <table id="prod_list" class="table table-hover smart-form has-tickbox">
        <thead>
            <tr>
                <th>Acc#</th>
                
                <th>Name</th>
                <th>Slug</th>
                <th width="120">Status</th>
                <th width="130">&nbsp;</th>
            </tr>
        </thead>
        <tbody>
             <?php foreach ($accounts as $account): ?>
                <tr>
                                    <td><?php echo h($account['Account']['id']); ?>&nbsp;</td>
                
                <td><?php echo $this->Html->link($account['Account']['name'], array('action' => 'view', $account['Account']['id'])); ?>&nbsp;</td>
                <td><?php echo $account['Account']['slug']; ?></td>
                <td><?php echo $account['Status']['value']; ?></td>
                    <td>
                        <div class="btn-group">
                            <a class="btn btn-sm btn-success" style='margin-right:5px;' href="accounts/account_set/<?php echo $account['Account']['id']; ?>">Use</a>
                                <a class="btn btn-sm btn-primary" href="accounts/edit/<?php echo $account['Account']['id']; ?>">Edit</a>
                                    <a class="btn btn-sm btn-primary" href="javascript:alert('under construction');"><i class="fa fa-times"></i></a>
                            </div>
                        
                    </td>
                </tr>
                <?php endforeach;?>
        </tbody>
    </table>
    
</div>
<!--start counts and paging-->
    <div class="tbl-footer">
        <div class="row">
            <div class="col-sm-6">
                <div class="dataTables_info" id="dt_basic_info">
                    <div class="footer_page_counter"><?php echo $this->Paginator->counter('Showing {:start} to {:end} of {:count}'); ?></div>
                </div>
            </div>

            <div id="footer_paginate" class="pull-right">
                <ul class="pagination">
                    <?php
                    echo $this->Paginator->first('<i class="fa fa-chevron-left"></i><i class="fa fa-chevron-left"></i>', array('escape' => false, 'tag' => 'li', 'disabledTag' => 'a'), null, array('escape' => false, 'class' => 'disabled', 'tag' => 'li', 'disabledTag' => 'a'));
                    echo $this->Paginator->prev('<i class="fa fa-chevron-left"></i>', array('escape' => false, 'tag' => 'li', 'disabledTag' => 'a'), null, array('escape' => false, 'class' => 'disabled', 'tag' => 'li', 'disabledTag' => 'a'));
                    echo $this->Paginator->numbers(array('separator' => '', 'class' => 'paging', 'tag' => 'li', 'currentClass' => 'active', 'currentTag' => 'a'));
                    echo $this->Paginator->next('<i class="fa fa-chevron-right"></i>', array('escape' => false, 'tag' => 'li', 'disabledTag' => 'a'), null, array('escape' => false, 'class' => 'disabled', 'tag' => 'li', 'disabledTag' => 'a'));
                    echo $this->Paginator->last('<i class="fa fa-chevron-right"></i><i class="fa fa-chevron-right"></i>', array('escape' => false, 'tag' => 'li', 'disabledTag' => 'a'), null, array('escape' => false, 'class' => 'disabled', 'tag' => 'li', 'disabledTag' => 'a'));
                    ?>
                </ul>
            </div>
        </div>
     </div>
    <!--end counts and paging-->
</div>
<?php } ?>