export function LivePriceCard({ product }) {
  return (
    <div className="price-card">
      {product.image && (
        <img src={product.image} alt={product.name} className="price-card__image" />
      )}
      <div className="price-card__content">
        <h3>{product.name}</h3>
        <p>{product.brand} {product.model}</p>
        <p className="price-card__category">{product.category}</p>
        {product.lowest_price ? (
          <p className="price-card__price">
            £{Number(product.lowest_price).toFixed(2)}{" "}
            <span>at {product.lowest_price_retailer}</span>
          </p>
        ) : (
          <p className="price-card__price price-card__price--na">Price unavailable</p>
        )}
        <p className={`price-card__stock price-card__stock--${product.stock_status}`}>
          {product.stock_status === "in_stock"
            ? "In stock"
            : product.stock_status === "out_of_stock"
              ? "Out of stock"
              : "Stock unknown"}
        </p>
        {product.lowest_price_url && (
          <a
            href={product.lowest_price_url}
            target="_blank"
            rel="noreferrer"
            className="price-card__link"
          >
            View at {product.lowest_price_retailer}
          </a>
        )}
      </div>
    </div>
  );
}
