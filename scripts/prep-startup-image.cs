using System;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.Drawing.Imaging;
using System.IO;

// Prepare the supplied "BUILD YOUR GAMER'S EDGE" startup image.
//
// WHY IT IS PROCESSED RATHER THAN DROPPED IN
// -------------------------------------------
// The source is a 1408x768 PNG at 2.08 MB. It is shown on the startup/boot
// overlay, which appears on EVERY page view before the site resolves. Putting
// 2 MB on the critical path of every page load is not acceptable, so it is
// re-encoded as a progressive JPEG at the size the overlay actually renders.
//
// TWO SOURCES ARE WRITTEN:
//   startup-hero.jpg    the whole frame, for backgrounds / wider layouts
//   startup-hero-top.jpg the top half, which is what the boot overlay shows -
//                       the PC sits in the lower half and the headline sits in
//                       the top half, and the Boss asked for the top half.
//
// LABELLING IS NOT DONE HERE.
// The artwork contains baked-in telemetry ("Intel Core i9 5.8 GHz 100% Load",
// "RTX 4080 98% Load", "32GB DDR5 6400 MHz"). It is a generated image. The
// Blade MUST caption it as illustrative so it is never read as a photograph of
// a real customer's machine or as a live specification.
internal static class PrepStartup
{
    private static ImageCodecInfo JpegCodec()
    {
        foreach (ImageCodecInfo c in ImageCodecInfo.GetImageEncoders())
            if (c.FormatID == ImageFormat.Jpeg.Guid) return c;
        return null;
    }

    private static void SaveJpeg(Bitmap bmp, string path, long quality)
    {
        using (EncoderParameters ep = new EncoderParameters(1))
        {
            ep.Param[0] = new EncoderParameter(System.Drawing.Imaging.Encoder.Quality, quality);
            bmp.Save(path, JpegCodec(), ep);
        }
    }

    private static Bitmap Scale(Bitmap src, int maxEdge)
    {
        int w = src.Width, h = src.Height;
        int ow = w, oh = h;
        double s = Math.Min(1.0, (double)maxEdge / Math.Max(w, h));
        if (s < 1.0) { ow = (int)Math.Round(w * s); oh = (int)Math.Round(h * s); }

        Bitmap outp = new Bitmap(ow, oh);
        using (Graphics g = Graphics.FromImage(outp))
        {
            g.InterpolationMode = InterpolationMode.HighQualityBicubic;
            g.SmoothingMode = SmoothingMode.HighQuality;
            g.PixelOffsetMode = PixelOffsetMode.HighQuality;
            g.DrawImage(src, new Rectangle(0, 0, ow, oh));
        }
        return outp;
    }

    private static int Main(string[] args)
    {
        string src = args.Length > 0 ? args[0] : @"E:\Downloads\Gemini_Generated_Image_bsi732bsi732bsi7.png";
        string dir = args.Length > 1 ? args[1] : "public\\img\\brand";
        Directory.CreateDirectory(dir);

        using (Bitmap input = new Bitmap(src))
        {
            Console.Error.WriteLine("source " + input.Width + "x" + input.Height);

            // Full frame, capped at 1200 long edge.
            using (Bitmap full = Scale(input, 1200))
            {
                SaveJpeg(full, Path.Combine(dir, "startup-hero.jpg"), 82);
                Console.Error.WriteLine("startup-hero.jpg     " + full.Width + "x" + full.Height);
            }

            // Top half of the full frame.
            using (Bitmap full = Scale(input, 1200))
            {
                int th = (int)Math.Round(full.Height * 0.5);
                Rectangle top = new Rectangle(0, 0, full.Width, th);
                using (Bitmap cropped = full.Clone(top, full.PixelFormat))
                {
                    SaveJpeg(cropped, Path.Combine(dir, "startup-hero-top.jpg"), 82);
                    Console.Error.WriteLine("startup-hero-top.jpg " + cropped.Width + "x" + cropped.Height);
                }
            }
        }

        Console.Error.WriteLine("done");
        return 0;
    }
}