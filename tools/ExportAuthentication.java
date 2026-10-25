import java.nio.file.*;
import java.security.*;
import java.math.BigInteger;
import java.util.*;
import javax.crypto.*;
import javax.crypto.spec.SecretKeySpec;
import com.google.gson.*;
import net.minecraft.util.Crypt;
import net.minecraft.network.FriendlyByteBuf;
import net.minecraft.network.protocol.login.*;
import io.netty.buffer.*;

/** Test-only key material. Production always generates a fresh key with OpenSSL's CSPRNG. */
public class ExportAuthentication {
    static String hex(byte[] b){return HexFormat.of().formatHex(b);}
    public static void main(String[] args) throws Exception {
        var random=SecureRandom.getInstance("SHA1PRNG");random.setSeed("PHP Minecraft Java 26.3 authentication fixture".getBytes(java.nio.charset.StandardCharsets.UTF_8));
        var generator=KeyPairGenerator.getInstance("RSA");generator.initialize(1024,random);var pair=generator.generateKeyPair();
        byte[] bytes=HexFormat.of().parseHex("00112233445566778899aabbccddeeff");var secret=new SecretKeySpec(bytes,"AES");
        byte[] nonce={1,2,3,4};var out=new JsonObject();
        out.addProperty("private_pkcs8",hex(pair.getPrivate().getEncoded()));out.addProperty("public_der",hex(pair.getPublic().getEncoded()));
        out.addProperty("secret",hex(bytes));out.addProperty("nonce",hex(nonce));
        out.addProperty("server_hash",new BigInteger(Crypt.digestData("",pair.getPublic(),secret)).toString(16));
        var hashes=new JsonObject();for(var name:List.of("Notch","jeb_","simon"))hashes.addProperty(name,new BigInteger(MessageDigest.getInstance("SHA-1").digest(name.getBytes(java.nio.charset.StandardCharsets.UTF_8))).toString(16));out.add("signed_sha1",hashes);
        byte[] plain=new byte[513];for(int i=0;i<plain.length;i++)plain[i]=(byte)i;
        out.addProperty("plaintext",hex(plain));out.addProperty("ciphertext",hex(Crypt.getCipher(Cipher.ENCRYPT_MODE,secret).doFinal(plain)));
        var buf=new FriendlyByteBuf(Unpooled.buffer());ClientboundHelloPacket.STREAM_CODEC.encode(buf,new ClientboundHelloPacket("",pair.getPublic().getEncoded(),nonce,true));byte[] encoded=new byte[buf.readableBytes()];buf.readBytes(encoded);buf.release();out.addProperty("hello_packet",hex(encoded));
        buf=new FriendlyByteBuf(Unpooled.buffer());var response=new ServerboundKeyPacket(secret,pair.getPublic(),nonce);ServerboundKeyPacket.STREAM_CODEC.encode(buf,response);encoded=new byte[buf.readableBytes()];buf.readBytes(encoded);buf.release();out.addProperty("key_packet",hex(encoded));
        Files.writeString(Path.of(args[0]),new GsonBuilder().setPrettyPrinting().create().toJson(out)+"\n");
    }
}
