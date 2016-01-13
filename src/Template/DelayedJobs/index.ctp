<div id="basic-stats" data-trigger="interval" data-url="<?=$this->Url->build(['action' => 'basicStats'])?>" data-interval="500" data-template="#basic-stats-template"></div>

<script type="text/x-underscore-template" id="basic-stats-template">
    <table class="table">
        <tr>
            <th></th>
        </tr>
    </table>
</script>

<script>
    $(function() {
       'use strict';

        function loadBlock($elem)
        {
            return function ()
            {
                var
                    url = $elem.data('url'),
                    interval = $elem.data('interval');

                $.getJSON(url, function (response)
                {
                    console.log(response);

                    setTimeout(loadBlock($elem), interval);
                });
            }
        }

        $('div[data-trigger]')
            .each(function () {
                var
                    $this = $(this);

                loadBlock($this)();
            });
    });
</script>
