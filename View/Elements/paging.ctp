<!--PAGING START-->
<div class="pull-right">
    <ul class="pagination m-t-0 m-b-0">
        <?php
        echo $this->Paginator->prev('← Previous', array('escape' => false, 'tag' => 'li', 'disabledTag' => 'a'), null, array('escape' => false, 'class' => 'disabled', 'tag' => 'li', 'disabledTag' => 'a'));
        echo $this->Paginator->numbers(array('separator' => '', 'class' => 'paging', 'tag' => 'li', 'currentClass' => 'active', 'currentTag' => 'a'));
        echo $this->Paginator->next('Next →', array('escape' => false, 'tag' => 'li', 'disabledTag' => 'a'), null, array('escape' => false, 'class' => 'disabled', 'tag' => 'li', 'disabledTag' => 'a'));
        ?>
    </ul>
</div>
<!--PAGING END-->