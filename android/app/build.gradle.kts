import java.io.File
import java.net.URI
import java.security.MessageDigest

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.kotlin.serialization)
}

val sfaceModelUrl =
    "https://media.githubusercontent.com/media/opencv/opencv_zoo/main/models/face_recognition_sface/face_recognition_sface_2021dec.onnx"
val sfaceModelSha256 =
    "0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79"
val sfaceModelFile = file("src/main/assets/models/face_recognition_sface_2021dec.onnx")

fun sha256Of(file: File): String {
    val md = MessageDigest.getInstance("SHA-256")
    file.inputStream().use { input ->
        val buf = ByteArray(8192)
        while (true) {
            val read = input.read(buf)
            if (read <= 0) break
            md.update(buf, 0, read)
        }
    }
    return md.digest().joinToString("") { "%02x".format(it) }
}

val ensureSfaceModel by tasks.registering {
    description = "Downloads and SHA-256 verifies OpenCV Zoo SFace ONNX model (Apache-2.0)."
    outputs.file(sfaceModelFile)
    doLast {
        sfaceModelFile.parentFile.mkdirs()
        if (sfaceModelFile.exists() && sha256Of(sfaceModelFile) == sfaceModelSha256) {
            logger.lifecycle("SFace ONNX model verified (${sfaceModelFile.length()} bytes).")
            return@doLast
        }
        logger.lifecycle("Downloading OpenCV Zoo SFace ONNX model (Apache-2.0)...")
        val tmp = File(sfaceModelFile.parentFile, "${sfaceModelFile.name}.tmp")
        URI(sfaceModelUrl).toURL().openStream().use { input ->
            tmp.outputStream().use { output -> input.copyTo(output) }
        }
        val actual = sha256Of(tmp)
        if (actual != sfaceModelSha256) {
            tmp.delete()
            error("SFace model SHA-256 mismatch! expected=$sfaceModelSha256 actual=$actual")
        }
        if (sfaceModelFile.exists()) sfaceModelFile.delete()
        if (!tmp.renameTo(sfaceModelFile)) {
            tmp.copyTo(sfaceModelFile, overwrite = true)
            tmp.delete()
        }
        logger.lifecycle("SFace ONNX model downloaded & verified successfully.")
    }
}

android {
    namespace = "vn.tad.attend"
    compileSdk = 35

    defaultConfig {
        applicationId = "vn.tad.attend"
        minSdk = 26
        targetSdk = 35
        versionCode = 8
        versionName = "1.0.7.21112026"

        ndk {
            abiFilters += listOf("arm64-v8a")
        }

        buildConfigField("String", "DEFAULT_SERVER_URL", "\"https://chamcong.tananhduong.com.vn\"")
        buildConfigField("String", "EXPECTED_MODEL_ID", "\"sface\"")
        buildConfigField("String", "EXPECTED_MODEL_VERSION", "\"2021dec\"")
        buildConfigField("int", "EMBEDDING_DIMENSIONS", "128")
        buildConfigField("String", "SFACE_ASSET_PATH", "\"models/face_recognition_sface_2021dec.onnx\"")
    }

    val ciKeystorePath = System.getenv("ANDROID_KEYSTORE_PATH")
    val ciKeystorePass = System.getenv("ANDROID_KEYSTORE_PASS")
    val ciKeyAlias = System.getenv("ANDROID_KEY_ALIAS")
    val ciKeyPass = System.getenv("ANDROID_KEY_PASS")
    val hasPersistentSigning = !ciKeystorePath.isNullOrBlank() &&
        !ciKeystorePass.isNullOrBlank() &&
        !ciKeyAlias.isNullOrBlank() &&
        !ciKeyPass.isNullOrBlank() &&
        File(ciKeystorePath).exists()

    signingConfigs {
        if (hasPersistentSigning) {
            create("persistent") {
                storeFile = File(ciKeystorePath!!)
                storePassword = ciKeystorePass
                keyAlias = ciKeyAlias
                keyPassword = ciKeyPass
            }
        }
    }

    buildTypes {
        debug {
            if (hasPersistentSigning) {
                signingConfig = signingConfigs.getByName("persistent")
            }
        }
        release {
            if (hasPersistentSigning) {
                signingConfig = signingConfigs.getByName("persistent")
            }
            isMinifyEnabled = false
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    androidResources {
        noCompress += listOf("onnx")
    }

    packaging {
        resources {
            excludes += "/META-INF/{AL2.0,LGPL2.1}"
        }
    }

    lint {
        abortOnError = true
        warningsAsErrors = false
        disable += listOf("GradleDependency", "NewerVersionAvailable", "AndroidGradlePluginVersion")
    }
}

tasks.named("preBuild") {
    dependsOn(ensureSfaceModel)
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.activity.compose)
    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.compose.ui)
    implementation(libs.androidx.compose.ui.graphics)
    implementation(libs.androidx.compose.ui.tooling.preview)
    implementation(libs.androidx.compose.material3)
    implementation(libs.androidx.compose.material.icons)

    implementation(libs.androidx.camera.core)
    implementation(libs.androidx.camera.camera2)
    implementation(libs.androidx.camera.lifecycle)
    implementation(libs.androidx.camera.view)

    implementation(libs.mlkit.face.detection)
    implementation(libs.onnxruntime.android)

    implementation(libs.okhttp)
    implementation(libs.kotlinx.serialization.json)
    implementation(libs.kotlinx.coroutines.android)

    testImplementation(libs.junit)
    testImplementation(libs.okhttp.mockwebserver)
    testImplementation(libs.kotlinx.coroutines.test)
}
