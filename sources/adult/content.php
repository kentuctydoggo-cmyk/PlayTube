<?php
// 18+ Section: public listing of videos uploaded with the "Only 18+" age restriction.
$pt->page_number = isset($_GET['page_id']) && is_numeric($_GET['page_id']) && $_GET['page_id'] > 0 ? $_GET['page_id'] : 1;
$pt->limit_per_page = !empty($pt->config->videos_load_limit) && is_numeric($pt->config->videos_load_limit) && $pt->config->videos_load_limit > 0 ? (int) $pt->config->videos_load_limit : 20;
$db->pageLimit = $pt->limit_per_page;

$videos = $db->where('privacy', 0)->where('user_id', $pt->blocked_array, 'NOT IN')->where('age_restriction', 2)->where('approved', 1)->where('is_short', 0)->orderBy('id', 'DESC')->objectbuilder()->paginate(T_VIDEOS, $pt->page_number);
$pt->total_pages = $db->totalPages;

$html_videos = '';
if (!empty($videos)) {
    foreach ($videos as $key => $video) {
        $video = $pt->video = PT_GetVideoByID($video, 0, 0, 0);
        $html_videos .= PT_LoadPage('videos/list', array(
            'ID' => $video->id,
            'VID_ID' => $video->id,
            'TITLE' => $video->title,
            'VIEWS' => $video->views,
            'VIEWS_NUM' => number_format($video->views),
            'USER_DATA' => $video->owner,
            'THUMBNAIL' => $video->thumbnail,
            'URL' => $video->url,
            'ajax_url' => $video->ajax_url,
            'TIME' => $video->time_ago,
            'DURATION' => $video->duration,
            'VIDEO_ID_' => PT_Slug($video->title, $video->video_id),
            'GIF' => $video->gif,
            'DESC' => $video->markup_description,
            'PRICE' => $video->sell_video,
            'CURRENCY' => $pt->config->main_payment_currency,
        ));
    }
}

if (empty($html_videos)) {
    $html_videos = '<div class="text-center no-content-found empty_state"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-video-off"><path d="M16 16v1a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2m5.66 0H14a2 2 0 0 1 2 2v3.34l1 1L23 7v10"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>' . $lang->no_videos_found_for_now . '</div>';
}

$pt->videos_count = count(ToArray($videos));
$pt->page_url_ = $pt->config->site_url . '/adult?page_id=' . $pt->page_number;
$pt->page = 'adult';
$pt->title = '18+ Section | ' . $pt->config->title;
$pt->description = $pt->config->description;
$pt->keyword = $pt->config->keyword;
$pt->content = PT_LoadPage('adult/content', array(
    'VIDEOS' => $html_videos,
));
