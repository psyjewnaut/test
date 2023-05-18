//защита от флуда
import org.springframework.stereotype.Component;
import java.util.ArrayList;
import java.util.Map;
import java.util.HashMap;
import java.util.Collections;
import org.springframework.beans.factory.annotation.Value;

@Component
public class FloodProtector {

    Map<Long, Long> users = new HashMap<>();

    ArrayList<Long> warns = new ArrayList<>();

    Map<Long, Long> ban_list = new HashMap<>();

    public ArrayList<Long> bans = new ArrayList<>();

    @Value("${flood.check_time}")
    long check_time;

    @Value("${flood.ban_time}")
    long ban_time;

    long old_time;

    int warns_for_ban = 5;

    public int isFlood(long chat_id, long now_time, byte type){
        if (ban_list.containsKey(chat_id)) {
            if (now_time - ban_list.get(chat_id) < ban_time * (1 + Math.pow(Collections.frequency(bans, chat_id), 2))) {
                return 3;
            } else {
                ban_list.remove(chat_id);
                bans.add(chat_id);
                return 4;
            }
        }
        if (!users.containsKey(chat_id))
            users.put(chat_id, now_time);
        else {
            old_time = users.get(chat_id);
            if (type == 3)
                old_time = old_time + 1000;
            users.replace(chat_id, now_time);
        }
        if (now_time - old_time < check_time){
            warns.add(chat_id);
            int count = Collections.frequency(warns, chat_id);
            if (count >= warns_for_ban) {
                ban_list.put(chat_id, now_time);
                for (int i = 0; i < count; i++)
                warns.remove(chat_id);
                return 2;
            }
            return 1;
        }
        return 0;
    }

}

//распознавание штрихкода по фото
import com.google.zxing.*;
import com.google.zxing.client.j2se.BufferedImageLuminanceSource;
import com.google.zxing.common.HybridBinarizer;
import org.json.JSONObject;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Service;
import thiskosherbot.Bot;

import javax.imageio.ImageIO;
import java.awt.*;
import java.awt.image.BufferedImage;
import java.io.*;
import java.net.URL;
import java.util.*;


@Service
public class Scanbarcode {

    private static final Logger LOG = LoggerFactory.getLogger(Scanbarcode.class);

    public Long scanFile(String file_id, Bot bot) throws IOException {
        long code = 0l;
        URL url = new URL("https://api.telegram.org/bot" + bot.token + "/getFile?file_id=" + file_id);
        BufferedReader in = new BufferedReader(new InputStreamReader(url.openStream()));
        String res = in.readLine();
        JSONObject jresult = new JSONObject(res);
        JSONObject path = jresult.getJSONObject("result");
        String file_path = path.getString("file_path");
        URL download = new URL("https://api.telegram.org/file/bot" +  bot.token + "/" + file_path);
        try {
            code = decode(download);
        }catch (Exception e) {e.printStackTrace();}
        return code;
    }

    public static Long decode(URL download) {
        BufferedImage image;
        Result result;
        long finalResult = 0l;
        try {
            image = ImageIO.read(download);
        } catch (IOException e) {
            e.printStackTrace();
            return 0l;
        }

        LuminanceSource source = new BufferedImageLuminanceSource(image);
        Binarizer binarizer = new HybridBinarizer(source);
        MultiFormatReader multiFormatReader = new MultiFormatReader();
        BinaryBitmap bitmap = new BinaryBitmap(binarizer);

        Map<DecodeHintType, Object> tmpHintsMap = new EnumMap<DecodeHintType, Object>(DecodeHintType.class);
        tmpHintsMap.put(DecodeHintType.TRY_HARDER, Boolean.TRUE);
        ArrayList<Enum> formats = new ArrayList<Enum>();
        //formats.add(BarcodeFormat.CODE_128);
        formats.add(BarcodeFormat.EAN_8);
        formats.add(BarcodeFormat.UPC_A);
        formats.add(BarcodeFormat.EAN_13);
        tmpHintsMap.put(DecodeHintType.POSSIBLE_FORMATS, formats);
        tmpHintsMap.put(DecodeHintType.PURE_BARCODE, Boolean.FALSE);
        try {
            result = multiFormatReader.decode(bitmap, tmpHintsMap);
            finalResult = Long.parseLong(result.getText());
        } catch (Exception e) {
            LOG.info("Fail first decode");
            e.printStackTrace();
        }
        if (finalResult == 0l) {
            double angle = 15.0;
            while (finalResult == 0l && angle < 61) {
                BufferedImage image_r = rotate(image, angle);
                LuminanceSource source_r = new BufferedImageLuminanceSource(image_r);
                Binarizer binarizer_r = new HybridBinarizer(source_r);
                BinaryBitmap bitmap_r = new BinaryBitmap(binarizer_r);
                try {
                    result = multiFormatReader.decode(bitmap_r, tmpHintsMap);
                    finalResult = Long.parseLong(result.getText());
                } catch (Exception e) {
                    LOG.info("Fail decode " + angle);
                    e.printStackTrace();
                }
                angle = angle + 15.0;
            }
        }

        if (finalResult != 0l)
        LOG.warn("New decode: " + finalResult);

        return finalResult;
    }

    public static BufferedImage rotate(BufferedImage img_r, Double angle) {
        BufferedImage rotated = new BufferedImage(img_r.getWidth(), img_r.getHeight(), img_r.getType());
        Graphics2D graphic = rotated.createGraphics();
        graphic.rotate(Math.toRadians(angle), img_r.getWidth()/2, img_r.getHeight()/2);
        graphic.setRenderingHint(RenderingHints.KEY_INTERPOLATION, RenderingHints.VALUE_INTERPOLATION_BILINEAR);
        graphic.drawRenderedImage(img_r, null);
        graphic.dispose();
        return rotated;
    }

}

//парсинг сайтов
import org.apache.http.client.config.RequestConfig;
import org.apache.http.client.methods.CloseableHttpResponse;
import org.apache.http.client.methods.HttpGet;
import org.apache.http.impl.client.CloseableHttpClient;
import org.apache.http.impl.client.HttpClientBuilder;
import org.apache.http.util.EntityUtils;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.scheduling.annotation.Async;
import org.springframework.scheduling.annotation.AsyncResult;
import org.springframework.stereotype.Service;
import java.util.concurrent.Future;

@Service
public class HttpRequests {

    private static final Logger LOG = LoggerFactory.getLogger(HttpRequests.class);
    RequestConfig config = RequestConfig.custom().setConnectTimeout(3000).setConnectionRequestTimeout(3000).setSocketTimeout(3000).build();
    RequestConfig config2 = RequestConfig.custom().setConnectTimeout(20000).setConnectionRequestTimeout(20000).setSocketTimeout(20000).build();

    @Async
    public Future<String> parse1(Long code){
        String url = "http://www.uhtt.ru/dispatcher/?query=SELECT%20GOODS%20BY%20CODE(" + code + ")%20FORMAT.TDDO(VIEW_GOODS)";
        String name = null;
        HttpGet Get = new HttpGet(url);
        try {
            CloseableHttpClient httpClient = HttpClientBuilder.create().setDefaultRequestConfig(config).build();
            CloseableHttpResponse response = httpClient.execute(Get);
            if (response.getStatusLine().getStatusCode() == 200) {
                String st = EntityUtils.toString(response.getEntity());
                if (st.substring(st.indexOf("<tbody>"), st.indexOf("</tbody>")).length() > 25) {
                    String log = st.substring(st.indexOf("uhtt-view--goods-table-item") + 36);
                    String log2 = log.substring(log.indexOf("</td>") + 14);
                    name = log2.substring(0, log2.indexOf("</td>"));
                } else {
                    LOG.info("Null parse 1: " + response.getStatusLine());
                }
            }
            else {
                LOG.warn("Bad response 1: " + response.getStatusLine());
            }
            response.getEntity().getContent().close();
            httpClient.close();
        } catch (Exception e) {
            e.printStackTrace();
        }

        return new AsyncResult<>(name);
    }

}

//структура http://puu.sh/JH4s1/21dad90a9d.png

