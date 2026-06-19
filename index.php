<?php

/*
| Render the resolved Sage view. The SANYUAN pages stream the original mirror
| markup (with an injected <base>) via \App\render_mirror(), so nothing else
| needs post-processing here.
*/

echo view(app('sage.view'), app('sage.data'))->render();
