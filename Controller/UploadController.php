<?php

namespace Comur\ImageBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

use Symfony\Component\Finder\Finder;

use Comur\ImageBundle\Handler\UploadHandler;

class UploadController extends Controller
{
    /**
     * Save uploaded image according to comur_image field configuration
     *
     * @param Request $request
     */
    public function uploadImageAction(Request $request
        /*, $uploadUrl, $paramName, $webDir, $minWidth=1, $minHeight=1*/
    ){
        $config = json_decode($request->request->get('config'),true);
        // var_dump($config);exit;
        $uploadUrl = $config['uploadConfig']['uploadUrl'];
        $uploadUrl = substr($uploadUrl, -strlen('/')) === '/' ? $uploadUrl : $uploadUrl . '/';
        
        // We must use a streamed response because the UploadHandler echoes directly
        $response = new StreamedResponse();
        
        $webDir = $config['uploadConfig']['webDir'];
        $webDir = substr($webDir, -strlen('/')) === '/' ? $webDir : $webDir . '/';
        $filename = sha1(uniqid(mt_rand(), true));
        
        $thumbsDir = $this->container->getParameter('comur_image.thumbs_dir');
        $thumbSize = $this->container->getParameter('comur_image.media_lib_thumb_size');

        $galleryDir = $this->container->getParameter('comur_image.gallery_dir');
        $gThumbSize = $this->container->getParameter('comur_image.gallery_thumb_size');

        $ext = $request->files->get('image_upload_file')->getClientOriginalExtension();//('image_upload_file');
        $completeName = $filename.'.'.$ext;
        $controller = $this;

        $handlerConfig = array(
            'upload_dir' => $uploadUrl,
            'param_name' => 'image_upload_file',
            'file_name' => $filename,
            'upload_url' => $config['uploadConfig']['webDir'],
            'min_width' => $config['cropConfig']['minWidth'],
            'min_height' => $config['cropConfig']['minHeight'],
            'image_versions' => array(
                'thumbnail' => array(
                    'upload_dir' => $uploadUrl.$thumbsDir.'/',
                    'upload_url' => $config['uploadConfig']['webDir'].'/'.$thumbsDir.'/',
                    'crop' => true,
                    'max_width' => $thumbSize,
                    'max_height' => $thumbSize
                )
            )
        );

        // if(isset($config['uploadConfig']['isGallery']) && $config['uploadConfig']['isGallery'])
        // {
        //     $handlerConfig['image_versions']['gallery_thumb'] = array(
        //         'upload_dir' => $uploadUrl . $thumbsDir . '/' . $galleryDir . '/',
        //         'upload_url' => $config['uploadConfig']['webDir'].'/'.$thumbsDir . '/' . $galleryDir . '/',
        //         'crop' => true,
        //         'max_width' => $gThumbSize,
        //         'max_height' => $gThumbSize
        //     );
        // }

        $response->setCallback(function () use($handlerConfig) {
            new UploadHandler($handlerConfig);
        });
        
        return $response->send();
    }

    /**
     * Crop image using jCrop and upload config parameters and create thumbs if needed
     *
     * @param Request $request
     */
    public function cropImageAction(Request $request
        /*, $uploadUrl, $webDir, $imageName, $x, $y, $w, $h, $tarW, $tarH*/
    ){
        $config = json_decode($request->request->get('config'),true);
        $params = $request->request->all();
        // var_dump($params);exit;
        $x = (int) round($params['x']);
        $y = (int) round($params['y']);
        $w = (int) round($params['w']);
        $h = (int) round($params['h']);
        $tarW = (int) round($config['cropConfig']['minWidth']);
        $tarH = (int) round($config['cropConfig']['minHeight']);

        // $forceResize = $config['cropConfig']['forceResize'];

        $uploadUrl = urldecode($config['uploadConfig']['uploadUrl']);
        $webDir = urldecode($config['uploadConfig']['webDir']);

        $imageName = $params['imageName'];

        $src = $uploadUrl.'/'.$imageName;

        if (!is_dir($uploadUrl.'/'.$this->container->getParameter('comur_image.cropped_image_dir').'/')) {
            mkdir($uploadUrl.'/'.$this->container->getParameter('comur_image.cropped_image_dir').'/', 0755, true);
        }
        $ext = pathinfo($imageName, PATHINFO_EXTENSION);
        $imageName = sha1(uniqid(mt_rand(), true)).'.'.$ext;
        $destSrc = $uploadUrl.'/'.$this->container->getParameter('comur_image.cropped_image_dir').'/'.$imageName;
        //$writeFunc($dstR,$src,$imageQuality);

        $destW = $tarW;
        $destH = $tarH;

        // if($forceResize){

            if(round($w/$h, 2) != round($tarW/$tarH, 2)){
                // var_dump($destW, $destH, $w, $h, $this->getMaxResizeValues($w, $h, $tarW, $tarH));exit;
                // $destW = $w;
                // $destH = $h;
                list($destW, $destH) = $this->getMinResizeValues($w, $h, $tarW, $tarH);
            }
            
        // }

        $this->resizeCropImage($destSrc,$src,0,0,$x,$y,$destW,$destH,$w,$h);

        $galleryThumbOk = false;
        $isGallery = isset($config['uploadConfig']['isGallery']) ? $config['uploadConfig']['isGallery'] : false;
        $galleryDir = $this->container->getParameter('comur_image.gallery_dir');
        $gThumbSize = $this->container->getParameter('comur_image.gallery_thumb_size');

        if($isGallery)
        {
            if(!isset($config['cropConfig']['thumbs']) || !($thumbs = $config['cropConfig']['thumbs']) || !count($thumbs))
            {
                $config['cropConfig']['thumbs'] = array();
            }
            $config['cropConfig']['thumbs'][] = array('maxWidth' => $gThumbSize, 'maxHeight' => $gThumbSize, 'forGallery' => true);
        }


        //Create thumbs if asked
        if(isset($config['cropConfig']['thumbs']) && ($thumbs = $config['cropConfig']['thumbs']) && count($thumbs))
        {
            $thumbDir = $uploadUrl.'/'.$this->container->getParameter('comur_image.cropped_image_dir') . '/' . $this->container->getParameter('comur_image.thumbs_dir').'/';
            if(!is_dir($thumbDir))
            {
                mkdir($thumbDir);
            }

            

            foreach($thumbs as $thumb){
                $maxW = $thumb['maxWidth'];
                $maxH = $thumb['maxHeight'];
                
                if(!isset($thumb['forGallery']) && $maxW == $gThumbSize && $maxH == $gThumbSize){
                    $galleryThumbOk = true;
                }
                if(isset($thumb['forGallery']) && $galleryThumbOk) continue;

                list($w, $h) = $this->getMaxResizeValues($destW, $destH, $maxW, $maxH);

                $thumbSrc = $thumbDir .$maxW.'x'.$maxH.'-'.$imageName;
                $this->resizeCropImage($thumbSrc, $destSrc, 0, 0, 0, 0, $w, $h, $destW, $destH);
            }
        }

        return new Response(json_encode(array('success' => true, 
            'filename'=>$this->container->getParameter('comur_image.cropped_image_dir').'/'.$imageName, 
            'galleryThumb' => $this->container->getParameter('comur_image.cropped_image_dir') . '/' . $this->container->getParameter('comur_image.thumbs_dir').'/'.$gThumbSize.'x'.$gThumbSize.'-' .$imageName)));
    }

    /**
     * Calculates and returns maximum size to fit in maxW and maxH for resize
     */
    private function getMaxResizeValues($srcW, $srcH, $maxW, $maxH){
        if($srcH/$srcW < $maxH/$maxW){
            $w = $maxW;
            $h = $srcH * ($maxW / $srcW);
        }
        else{
            $h = $maxH;
            $w = $srcW * ($maxH / $srcH);
        }
        return array($w, $h);
    }

    /**
     * Calculates and returns min size to fit in minW and minH for resize
     */
    private function getMinResizeValues($srcW, $srcH, $minW, $minH){
        if($srcH/$srcW > $minH/$minW){
            $w = $minW;
            $h = $srcH * ($minW / $srcW);
        }
        else{
            $h = $minH;
            $w = $srcW * ($minH / $srcH);
        }
        return array($w, $h);
    }

    /**
     * Calculates and returns maximum size to fit in maxW and maxH for crop
     */
    private function getMaxCropValues($srcW, $srcH, $maxW, $maxH)
    {
        $x = $y = 0;
        if($srcH/$srcW > $maxH/$maxW){
            $w = $srcW;
            $h = $srcH * ($maxW / $maxH);
            $y = round($srcH - $h / 2, 0);
        }
        else{
            $h = $srcH;
            $w = $srcW * ($maxtH / $maxW);
            $x = round($srcW - $w / 2, 0);
        }
        return array($w, $h, $x, $y);
    }

    /**
     * Returns files from required directory
     *
     * @param Request $request
     */
    public function getLibraryImagesAction(Request $request){
        $finder = new Finder();

        $finder->sortByType();
        $finder->depth('== 0');
        $result = array();
        $files = array();

        $result['thumbsDir'] = $this->container->getParameter('comur_image.thumbs_dir');
        
        foreach ($finder->in($request->request->get('dir'))->files() as $file) {
            $files[] = $file->getFilename();
        }
        $result['files'] = $files;
        // var_dump(json_encode($result));exit;

        return new Response(json_encode($result));
    }

    /**
     * Crops or resizes image and writes it on disk
     */
    private function resizeCropImage($destSrc, $imgSrc, $destX, $destY, $srcX, $srcY, $destW, $destH, $srcW, $srcH)
    {
        $type = strtolower(pathinfo($imgSrc, PATHINFO_EXTENSION));

        switch ($type) {
            case 'jpg':
            case 'jpeg':
                $srcFunc = 'imagecreatefromjpeg';
                $writeFunc = 'imagejpeg';
                $imageQuality = 100;
                break;
            case 'gif':
                $srcFunc = 'imagecreatefromgif';
                $writeFunc = 'imagegif';
                $imageQuality = null;
                break;
            case 'png':
                $srcFunc = 'imagecreatefrompng';
                $writeFunc = 'imagepng';
                $imageQuality = 9;
                break;
            default:
                return false;
        }

        $imgR = $srcFunc($imgSrc);
        
        if(round($srcW/$srcH, 2) != round($destW/$destH, 2)){
            $destW = $srcW;
            $destH = $srcH;
        }
        $dstR = imagecreatetruecolor( $destW, $destH );

        imagecopyresampled($dstR,$imgR,$destX,$destY,$srcX,$srcY,$destW,$destH,$srcW,$srcH);

        switch ($type) {
            case 'gif':
            case 'png':
                imagecolortransparent($dstR, imagecolorallocate($dstR, 0, 0, 0));
            case 'png':
                imagealphablending($dstR, false);
                imagesavealpha($dstR, true);
                break;
        }
        
        $writeFunc($dstR,$destSrc,$imageQuality);
    }
}
